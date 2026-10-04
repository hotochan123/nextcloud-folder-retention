<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Db\LogEntry;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\RetentionRoot;
use OCP\App\IAppManager;
use OCP\Files\Config\IHomeMountProvider;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\ISharedStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Lock\LockedException;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verschiebt eine Datei in den Papierkorb (files_trashbin bzw. Team-Ordner-Papierkorb).
 * Endgültiges Löschen ist nie gewollt: Ohne nachweislich passenden Papierkorb wird abgelehnt,
 * und nach dem Löschen wird nachgesehen, ob die Datei dort angekommen ist.
 *
 * Warum der Aufwand: files_trashbin legt die Datei über Filesystem::getView() ab – das ist
 * die Sicht des Kontos, für das das Dateisystem zuerst eingerichtet wurde. Passt sie nicht
 * zur Datei, schlägt das Verschieben fehl, und der Speicher-Wrapper löscht STILL endgültig
 * (files_trashbin/lib/Storage.php, doDelete). Dasselbe passiert, wenn der Papierkorb für den
 * angemeldeten Benutzer nicht aktiv ist – im Cron gibt es keinen, bei auf Gruppen beschränktem
 * files_trashbin also immer –, bei Dateien über der Papierkorb-Höchstgröße und bei *.part.
 * Deshalb: Dateisystem und aktiven Benutzer auf genau das Konto umstellen, dem die Datei gehört.
 *
 * Folge davon: Nextcloud hält dieses Konto für den Löschenden – Papierkorb („Gelöscht von“ im
 * Team-Ordner), Aktivität und admin_audit nennen den Besitzer bzw. ein Team-Ordner-Mitglied.
 * Damit sich das der App zuordnen lässt, steht das Konto im Protokolleintrag („über Konto …“),
 * und bei Team-Ordnern ist es stabil das erste geeignete Mitglied in sortierter Reihenfolge.
 *
 * Zwei weitere Wege am Papierkorb vorbei:
 * - Räumung wegen Platz: files_trashbin räumt nach dem Verschieben (Expire-Job) so lange
 *   Einträge endgültig ab, bis trashbin_size bzw. 50 % des freien Quota-Platzes wieder reichen;
 *   groupfolders leert den Team-Ordner-Papierkorb, sobald Inhalt + Papierkorb die Quota
 *   übersteigen. Vorher nachrechnen, die Verschiebungen dieses Laufs und die mitwandernden
 *   Versionen (files_trashbin/versions) eingerechnet.
 * - Ausnahme im Papierkorb-Backend: TrashManager::moveToTrash setzt dann trashPaused nicht
 *   zurück, und JEDE weitere Löschung im selben Prozess ginge endgültig. Daher vor jedem
 *   Versuch resumeTrash(), und nach einer Ausnahme beim Löschen für den Rest des Laufs nichts mehr.
 *   Auch nach der Ausnahme und beim Aufräumen resumeTrash(): cron.php arbeitet im selben Prozess
 *   weitere Jobs ab, deren Löschungen sonst ebenfalls endgültig wären.
 *   Außerdem merkt sich LegacyTrashBackend den Pfad (deletedFiles) und räumt ihn nach einer
 *   Ausnahme nicht ab – ein späterer Versuch am selben Pfad liefert sofort false, und
 *   files_trashbin löscht endgültig. Ein langlebiger Prozess (occ background-job:worker) führt
 *   den RetentionJob mehrfach aus: Diese Pfade bleiben daher für den Rest des Prozesses gesperrt.
 * - Gleicher Name in derselben Sekunde: files_trashbin und groupfolders nennen den Eintrag
 *   „<name>.d<time()>“ und überschreiben ein vorhandenes Ziel (samt Versionen) endgültig. Daher
 *   je Papierkorb und Name warten, bis eine neue Sekunde angebrochen ist – nach den Löschungen
 *   dieses Laufs und nach dem Filecache (Einträge eines eben beendeten anderen Laufs) –, und nach
 *   jeder Löschung nachsehen, ob die gleichnamigen Einträge dieses Laufs aus derselben Sekunde
 *   noch da sind. „Gleichnamig“ wie im
 *   Papierkorb: files_trashbin kürzt lange Namen in der Mitte (trashNameKeys).
 *   Auch ein Nutzer kann in derselben Sekunde eine gleichnamige Datei löschen und den Eintrag
 *   der App überschreiben (Trashbin::move2trash: Namenssperre schon frei, dann unlink). Das fällt
 *   nur auf, wenn die App später noch einmal nachsieht – daher am Laufende verifyRecentTrash().
 * - Kopfzeile „X-NC-Skip-Trashbin: true“: files_trashbin löscht dann am Papierkorb vorbei. Im
 *   Web-Cron (AJAX/Webcron) läuft der Job in einer anonymen Anfrage an cron.php – jeder könnte die
 *   Kopfzeile setzen. Daher nur im CLI-Kontext (System-Cron, occ) löschen.
 */
class Deleter {
	/** Konto, dessen Dateisystem gerade eingerichtet ist; null = noch nicht umgestellt */
	private ?string $contextUid = null;
	private bool $switched = false;
	private ?IUser $previousUser = null;
	/** @var array<string, string> uid → Grund, warum sein Kontext in diesem Lauf nicht nutzbar ist */
	private array $contextFailed = [];
	/** gesetzt = Papierkorb hat in diesem Lauf eine Ausnahme geworfen, nichts mehr löschen */
	private ?string $halted = null;
	/**
	 * Prozessweit, übersteht releaseContext(): „Speicher:Pfad“ der Dateien, bei deren Löschen eine
	 * Ausnahme flog – der Papierkorb hält sie womöglich für schon verschoben (LegacyTrashBackend).
	 *
	 * @var array<string, true>
	 */
	private static array $unreliablePaths = [];
	/** @var array<string, int|float> Papierkorb → Größe beim ersten Blick in diesem Lauf */
	private array $trashBase = [];
	/** @var array<string, int|float> Papierkorb → in diesem Lauf hineinverschobene Bytes */
	private array $trashAdded = [];
	/**
	 * In diesem Lauf in den Papierkorb verschobene Dateien, je Papierkorb und Name
	 * (Name wie im Papierkorb, klein geschrieben): Sekunde der Löschung und Datei.
	 *
	 * verified = als „deleted“ verbucht (nur diese prüft der Sicherheitsgurt nach).
	 *
	 * @var array<string, array<string, list<array{at: int, root: RetentionRoot, file: FileRow, verified: bool}>>>
	 */
	private array $trashed = [];
	/**
	 * Alle in diesem Lauf als „deleted“ verbuchten Dateien, für verifyRecentTrash (anders als
	 * $trashed nie vorzeitig ausgedünnt): Datei-ID → Sekunden vor (from) und nach (at) dem Löschen.
	 *
	 * @var array<int, array{from: int, at: int, root: RetentionRoot, file: FileRow}>
	 */
	private array $verifiedTrash = [];
	/** @var list<array{0: RetentionRoot, 1: FileRow, 2: string}> verloren gegangene Papierkorb-Einträge (Bereich, Datei, Grund) */
	private array $lost = [];
	/** @var array<int, true> Datei-IDs, deren Verlust schon gemeldet ist */
	private array $lostIds = [];
	/** wurde im aktuellen Versuch File::delete() aufgerufen? */
	private bool $attempted = false;
	/** Sekunde unmittelbar vor File::delete() im aktuellen Versuch */
	private int $attemptAt = 0;

	public function __construct(
		private IRootFolder $rootFolder,
		private IAppManager $appManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IConfig $config,
		private IAppConfig $appConfig,
		private FileCacheReader $fileCache,
		private LoggerInterface $logger,
		private ContentLanguage $language,
	) {
	}

	/** Text in the fixed instance language (stored in the log / shown as lock reason) */
	private function t(string $text, array $parameters = []): string {
		return $this->language->l10n()->t($text, $parameters);
	}

	/**
	 * @return array{0: string, 1: ?string} [LogEntry::STATUS_*, Meldung]
	 */
	public function delete(RetentionRoot $root, FileRow $file): array {
		if ($this->halted !== null) {
			return [LogEntry::STATUS_ERROR, $this->t('Deletion halted for this run: %s', [$this->halted])];
		}
		if (isset(self::$unreliablePaths[$file->storageId . ':' . $file->path])) {
			return [LogEntry::STATUS_ERROR, $this->t('Trash bin state of this process is unreliable (earlier exception while deleting at this path) – only after a process restart; not deleted')];
		}
		if (str_ends_with(strtolower($file->path), '.part')) {
			// Nextcloud hält *.part für Upload-Reste und löscht sie am Papierkorb vorbei
			return [LogEntry::STATUS_ERROR, $this->t('Extension .part – Nextcloud would delete the file permanently, bypassing the trash bin; not deleted')];
		}
		if (!$this->isCli()) {
			// Web-Cron: anonyme Anfrage, X-NC-Skip-Trashbin wäre von außen setzbar, max_execution_time
			// könnte zwischen Löschen und Protokoll abbrechen
			return [LogEntry::STATUS_ERROR, $this->t('Deleting only with system cron or occ (background jobs run via AJAX/Webcron) – not deleted')];
		}

		$this->attempted = false;
		$result = $this->attemptDelete($root, $file);
		if ($this->attempted) {
			$this->checkEarlierTrashEntries($root, $file, $this->attemptAt);
			// auch ohne Nachweis merken: Liegt sie doch im Papierkorb, belegt sie den Namen dieser Sekunde
			$this->rememberTrashed($root, $file, $result[0] === LogEntry::STATUS_DELETED);
		}
		return $result;
	}

	/**
	 * @return array{0: string, 1: ?string}
	 */
	private function attemptDelete(RetentionRoot $root, FileRow $file): array {
		$lastError = $this->t('File not found in any user view');
		foreach ($this->candidates($root) as $uid) {
			$user = $this->userManager->get($uid);
			if ($user === null) {
				$lastError = $this->t('Account %s does not exist', [$uid]);
				continue;
			}
			// Versionen wandern beim Löschen mit in den Papierkorb und zählen dort mit
			$versions = $this->versionsSize($root, $user, $file);
			if ($versions === null) {
				$lastError = $this->t('Size of versions unknown – space in the trash bin cannot be calculated; not deleted');
				continue;
			}
			$blocker = $this->trashBlocker($root, $user, $file, $versions);
			if ($blocker !== null) {
				$lastError = $blocker;
				continue;
			}
			$contextError = $this->enterContext($user);
			if ($contextError !== null) {
				$lastError = $contextError;
				continue;
			}

			// Erst warten, dann prüfen: Zwischen der letzten Prüfung (Ort, mtime) und dem Löschen
			// darf keine Wartezeit liegen, in der ein Sync-Client die Datei ersetzen könnte
			$busy = $this->awaitFreshTrashSecond($root, $file);
			if ($busy !== null) {
				return [LogEntry::STATUS_ERROR, $busy];
			}

			$error = null;
			$deleting = false;
			$node = null;
			try {
				$node = $this->findNode($root, $uid, $file);
				if ($node === null) {
					continue;
				}
				if ($node->getMTime() !== $file->mtime) {
					return [LogEntry::STATUS_SKIPPED_CHANGED, $this->t('File was modified since it was evaluated')];
				}
				// getById findet die Datei auch an einem neuen Ort – zwischen Neuprüfung und hier
				// (Kontowechsel) kann sie verschoben worden sein, mit gleicher ID und mtime
				$entry = $this->fileCache->getEntry($file->fileId);
				if ($entry === null || $entry['storage'] !== $file->storageId || $entry['path'] !== $file->path) {
					return [LogEntry::STATUS_SKIPPED_CHANGED, $this->t('File was moved since it was evaluated')];
				}
				// Ohne Löschrecht (Gruppenrechte, ACL im Team-Ordner) wirft File::delete, bevor der
				// Papierkorb berührt wird – das nächste Mitglied versuchen, nicht den Lauf anhalten
				if (!$node->isDeletable()) {
					$lastError = $this->t('Account %s may not delete the file (missing delete permission, e.g. group permissions or ACL)', [$uid]);
					continue;
				}
				$deleting = true;
				$this->attempted = true;
				$this->attemptAt = $this->now();
				$this->resumeTrash();
				$node->delete();
			} catch (LockedException $e) {
				$error = [LogEntry::STATUS_SKIPPED_LOCKED, $this->t('File is locked: %s', [$e->getMessage()])];
				// Sperre auf der Datei selbst und (geteilt) auf ihren Elternordnern holt View::unlink
				// (View::lockFile), bevor der Papierkorb drankommt – z. B. ein lesender Sync-Client oder
				// ein Umbenennen des Ordners: nur diese Datei überspringen. Sperren an anderen Pfaden
				// können aus dem Papierkorb stammen und halten den Lauf weiter an.
				if ($node !== null && self::lockedBeforeTrash($e->getPath(), $node->getPath())) {
					$deleting = false;
				}
			} catch (NotPermittedException $e) {
				$error = [LogEntry::STATUS_ERROR, $this->t('No permission via user %1$s: %2$s', [$uid, $e->getMessage()])];
			} catch (Throwable $e) {
				$this->logger->warning('folder_retention: deletion failed', ['exception' => $e, 'fileId' => $file->fileId]);
				$error = [LogEntry::STATUS_ERROR, $e::class . ': ' . $e->getMessage()];
			}
			if ($error !== null && $deleting) {
				// Ausnahme aus dem Löschen selbst: Papierkorb-Zustand dieses Prozesses ist unzuverlässig.
				// trashPaused trotzdem zurücksetzen – sonst löschen Folgejobs im selben Prozess endgültig
				try {
					$this->resumeTrash();
				} catch (Throwable) {
				}
				$this->halt($error[1], $uid, $file);
				self::$unreliablePaths[$file->storageId . ':' . $file->path] = true;
				$error[1] = $this->t('%s – further deletions halted for this run', [$error[1]]);
			}

			// Auch nach einer Ausnahme nachsehen: Sie kann nach dem Entfernen fliegen
			$where = $this->whereIsFile($root, $file);
			if ($where === 'trash') {
				$area = $this->trashArea($root);
				$this->trashAdded[$area[0]] = ($this->trashAdded[$area[0]] ?? 0) + max(0, $file->size) + $versions;
				return [LogEntry::STATUS_DELETED, $this->deletedVia($uid)];
			}
			if ($where === 'gone') {
				$this->logger->error('folder_retention: file was deleted permanently instead of being moved to the trash bin', [
					'fileId' => $file->fileId, 'path' => $file->path, 'user' => $uid, 'root' => $root->label,
				]);
				return [LogEntry::STATUS_DELETED_FINAL, $this->t('File is gone but did not arrive in the trash bin (account %s) – Nextcloud deleted it permanently', [$uid])];
			}
			if ($where !== 'unchanged') {
				return [LogEntry::STATUS_ERROR, $this->t('File is at an unexpected location after deletion (%s) – please check', [$where])];
			}
			if ($error !== null && ($deleting || $error[0] === LogEntry::STATUS_SKIPPED_LOCKED)) {
				// Kein weiterer Kandidat: nach einer Ausnahme beim Löschen ginge der nächste Versuch
				// womöglich am Papierkorb vorbei
				return [$error[0], mb_substr($error[1], 0, 1000)];
			}
			$lastError = $error[1] ?? $this->t('File still present unchanged after deletion');
		}
		return [LogEntry::STATUS_ERROR, mb_substr($lastError, 0, 1000)];
	}

	/**
	 * Gesperrter Pfad = die Datei selbst oder einer ihrer Ordner ab „/<uid>/files“? Diese Sperren
	 * holt View::lockFile vor dem Papierkorb. „/<uid>“ allein nicht – das sperren auch
	 * View-Operationen im Papierkorb (Versionen kopieren).
	 */
	private static function lockedBeforeTrash(string $locked, string $nodePath): bool {
		$locked = trim($locked, '/');
		$own = trim($nodePath, '/');
		return $locked === $own || (str_contains($locked, '/') && str_starts_with($own, $locked . '/'));
	}

	/**
	 * Protokollmeldung zu „deleted“: Papierkorb, Aktivität und Audit-Log nennen dieses Konto als
	 * Löschenden – die Meldung macht klar, dass es die App war.
	 */
	public function deletedVia(string $uid): string {
		return $this->t('Moved to the trash bin via account %1$s (trash bin/activity name %1$s as the deleting user; folder_retention deleted it)', [$uid]);
	}

	/**
	 * Konten, über deren Sicht gelöscht werden darf. Konto-Bereiche: nur der Besitzer.
	 * Team-Ordner: Mitglieder in fester, sortierter Reihenfolge – Nextcloud nennt das gewählte
	 * Konto als Löschenden, das soll nicht davon abhängen, welcher Kontext gerade eingerichtet ist.
	 *
	 * @return list<string>
	 */
	private function candidates(RetentionRoot $root): array {
		if ($root->isAccount()) {
			return array_slice($root->userIds, 0, 1);
		}
		$ids = array_values(array_unique($root->userIds));
		sort($ids, SORT_STRING);
		return $ids;
	}

	/**
	 * Gründe, aus denen Nextcloud die Datei am Papierkorb vorbei löschen würde – vorher erkennbare.
	 */
	private function trashBlocker(RetentionRoot $root, IUser $user, FileRow $file, int|float $versions): ?string {
		$uid = $user->getUID();
		if (!$this->appManager->isEnabledForUser('files_trashbin', $user)) {
			return $this->t('Trash bin (files_trashbin) is not enabled for %s – deletion refused', [$uid]);
		}
		if ($this->requestSkipsTrashbin()) {
			return $this->t('Request carries “X-NC-Skip-Trashbin: true” – Nextcloud would delete bypassing the trash bin; not deleted');
		}
		return $root->isAccount() ? $this->accountTrashBlocker($root, $user, $file, $versions) : $this->teamTrashBlocker($root, $file);
	}

	/**
	 * Konto-Papierkorb: rechnet wie files_trashbin (Trashbin::getConfiguredTrashbinSize,
	 * calculateFreeSpace) mit dem Stand NACH dem Verschieben. Ist danach kein Platz mehr frei,
	 * räumt der Expire-Job Einträge endgültig ab – womöglich gerade die verschobenen.
	 * Gemessen wird ganz files_trashbin, also samt der mitverschobenen Versionen ($versions);
	 * die Belegung wie dort über die Konto-Wurzel (bei Objektspeicher samt Papierkorb und Versionen).
	 */
	private function accountTrashBlocker(RetentionRoot $root, IUser $user, FileRow $file, int|float $versions): ?string {
		$uid = $user->getUID();
		// Höchstgröße (trashbin_size): Konto-Wert vor App-Wert
		$limit = $this->config->getUserValue($uid, 'files_trashbin', 'trashbin_size', '-1');
		if (!is_numeric($limit) || (float)$limit <= -1) {
			try {
				$limit = $this->appConfig->getValueString('files_trashbin', 'trashbin_size', '-1');
			} catch (AppConfigTypeConflictException) {
				// occ trashbin:size speichert eine Zahl, files_trashbin liest Text – jedes Verschieben
				// in den Papierkorb scheitert dann mit einer Ausnahme
				return $this->t('trashbin_size is stored with the wrong type (e.g. after occ trashbin:size) – Nextcloud then fails to move files to the trash bin; not deleted. Fix: occ config:app:delete files_trashbin trashbin_size and set the value again');
			} catch (Throwable $e) {
				return $this->t('trashbin_size not readable (%s); not deleted', [$e->getMessage()]);
			}
			if (!is_numeric($limit)) {
				$limit = '-1';
			}
		}
		$size = max(0, $file->size);
		$moved = $size + $versions;
		$trash = $this->trashUsed($root);
		if ((float)$limit > -1) {
			if ($size >= (float)$limit) {
				return $this->t('File is larger than the maximum trash bin size (trashbin_size) – Nextcloud would delete it permanently; not deleted');
			}
			if ((float)$limit - $trash - $moved <= 0) {
				return $this->t('Trash bin would purge it permanently right away due to quota/size (trashbin_size %1$s, in trash bin %2$s%3$s) – not deleted', [Util::humanFileSize((int)$limit), Util::humanFileSize((int)$trash), $this->versionsNote($versions)]);
			}
			return null;
		}

		$quota = $user->getQuota();
		if ($quota === null || $quota === '' || $quota === 'none') {
			return null; // ohne Quota räumt files_trashbin nur bei voller Platte
		}
		$quotaBytes = Util::computerFileSize($quota);
		if ($quotaBytes === false || $quotaBytes < 0) {
			return null; // ungültige Quota gilt bei files_trashbin als unbegrenzt
		}
		// Belegung wie files_trashbin: Größe der Konto-Wurzel, nach dem Verschieben
		$files = $this->fileCache->getSize($root->storageId, 'files');
		$files = $files === null || $files < 0 ? 0 : $files;
		if ($this->homeCacheCountsFilesOnly($root->storageId)) {
			// Lokaler Speicher (Storage\Home mit HomeCache): Die Wurzel meldet die Größe von files/,
			// die Datei ist danach nicht mehr darin. Die Wurzelzeile im Filecache ist hier
			// bedeutungslos (0 oder veraltet) und bleibt außen vor.
			$used = max(0, $files - $size);
		} else {
			// Objektspeicher (HomeObjectStoreStorage, normaler Cache): Die Wurzelzeile zählt
			// files/, Papierkorb und Versionen – die Datei bleibt nach dem Verschieben darin.
			// Unbekannte Speicherart ebenso: lieber zu viel Belegung als zu wenig.
			$whole = $this->fileCache->getSize($root->storageId, '');
			$whole = $whole === null || $whole < 0 ? 0 : $whole;
			$used = max($files, $whole);
		}
		$free = $quotaBytes - $used;
		$available = $free > 0 ? $free * 0.5 - ($trash + $moved) : $free - ($trash + $moved);
		if ($available <= 0) {
			return $this->t('Trash bin would purge it permanently right away due to quota/size (quota %1$s, used %2$s, in trash bin %3$s%4$s) – not deleted', [Util::humanFileSize((int)$quotaBytes), Util::humanFileSize((int)$used), Util::humanFileSize((int)$trash), $this->versionsNote($versions)]);
		}
		return null;
	}

	/**
	 * Rechnet Nextcloud für dieses Konto die Belegung nur aus files/ (HomeCache)? Das gilt für den
	 * lokalen Home-Storage („home::<uid>“, ältere Installationen „local::<pfad>“). Objektspeicher als
	 * Primärspeicher heißt „object::user:<uid>“ und nimmt den normalen Cache.
	 */
	private function homeCacheCountsFilesOnly(int $storageId): bool {
		$id = $this->fileCache->storageStringId($storageId);
		return $id !== null && (str_starts_with($id, 'home::') || str_starts_with($id, 'local::'));
	}

	private function versionsNote(int|float $versions): string {
		return $versions > 0 ? $this->t(', including moved versions %s', [Util::humanFileSize((int)$versions)]) : '';
	}

	/**
	 * Größe der Versionen, die files_trashbin beim Löschen mit in den Papierkorb verschiebt
	 * (Trashbin::retainVersions, nur bei aktivem files_versions). Team-Ordner: groupfolders
	 * lässt Versionen liegen und zählt sie bei der Räumung nicht mit.
	 *
	 * @return int|float|null null = unbekannt
	 */
	private function versionsSize(RetentionRoot $root, IUser $user, FileRow $file): int|float|null {
		if (!$root->isAccount() || !$this->appManager->isEnabledForUser('files_versions', $user)) {
			return 0;
		}
		return $this->fileCache->versionsSize($root->storageId, $file->path);
	}

	/**
	 * Team-Ordner: groupfolders (ExpireGroupTrash, stündlich) leert den Papierkorb, solange
	 * Inhalt + Papierkorb die Quota übersteigen. Das Verschieben ändert die Summe nicht –
	 * ist sie schon zu groß, wäre auch diese Datei bald endgültig weg.
	 */
	private function teamTrashBlocker(RetentionRoot $root, FileRow $file): ?string {
		$quota = $this->fileCache->groupFolderQuota($root->rootId);
		if ($quota === null) {
			return $this->t('Quota of the team folder cannot be determined (groupfolders table) – not deleted');
		}
		if ($quota === -4) {
			$quota = $this->config->getSystemValueInt('groupfolders.quota.default', -3);
		}
		if ($quota <= 0) {
			return null; // unbegrenzt
		}
		$content = $this->fileCache->getSize($root->storageId, $root->rootPath);
		if ($content === null || $content < 0) {
			return $this->t('Usage of the team folder unknown, quota set – not deleted');
		}
		$size = max(0, $file->size);
		$trash = $this->trashUsed($root);
		if ($quota < max(0, $content - $size) + $trash + $size) {
			return $this->t('Trash bin would purge it permanently right away due to quota/size (team folder quota %1$s, used %2$s, in trash bin %3$s) – not deleted', [Util::humanFileSize($quota), Util::humanFileSize((int)$content), Util::humanFileSize((int)$trash)]);
		}
		return null;
	}

	/**
	 * Papierkorb eines Bereichs: [Schlüssel, Storage, interner Pfad].
	 * Team-Ordner: „__groupfolders/<id>“ → „__groupfolders/trash/<id>“; mit eigenem Storage je
	 * Team-Ordner liegen Dateien unter „files“, der Papierkorb unter „trash“.
	 *
	 * @return array{0: string, 1: int, 2: string}
	 */
	private function trashArea(RetentionRoot $root): array {
		if ($root->isAccount()) {
			$path = 'files_trashbin';
		} elseif ($root->rootPath === 'files') {
			$path = 'trash';
		} else {
			$path = (string)preg_replace('#^(.*/)?([^/]+)$#', '${1}trash/${2}', $root->rootPath);
		}
		return [$root->storageId . ':' . $path, $root->storageId, $path];
	}

	/**
	 * Aktuelle Papierkorbgröße – mindestens der Stand beim ersten Blick plus das in diesem Lauf
	 * Verschobene (falls Nextcloud die Ordnergröße noch nicht nachgezogen hat).
	 */
	private function trashUsed(RetentionRoot $root): int|float {
		[$key, $storageId, $path] = $this->trashArea($root);
		$read = $this->fileCache->getSize($storageId, $path);
		$read = $read === null || $read < 0 ? 0 : $read;
		$this->trashBase[$key] ??= $read;
		return max($read, $this->trashBase[$key] + ($this->trashAdded[$key] ?? 0));
	}

	/** Für den Rest des Laufs nichts mehr löschen; Warnung ins Nextcloud-Log */
	private function halt(string $reason, string $uid, FileRow $file): void {
		$this->halted ??= mb_substr($reason, 0, 500);
		$this->logger->warning('folder_retention: exception while moving to the trash bin – further deletions halted for this run, as Nextcloud might bypass the trash bin afterwards', [
			'fileId' => $file->fileId, 'path' => $file->path, 'user' => $uid, 'reason' => $reason,
		]);
	}

	/** Grund, warum in diesem Lauf nicht mehr gelöscht wird; null = alles in Ordnung */
	public function haltReason(): ?string {
		return $this->halted;
	}

	/**
	 * Dateisystem und aktiven Benutzer auf $user umstellen – nur, wenn nicht schon geschehen.
	 *
	 * @return string|null Fehlermeldung, wenn der Kontext nicht nachweislich stimmt
	 */
	private function enterContext(IUser $user): ?string {
		$uid = $user->getUID();
		if (isset($this->contextFailed[$uid])) {
			return $this->contextFailed[$uid];
		}
		if ($this->contextUid !== $uid || $this->viewRoot() !== "/$uid/files" || $this->userSession->getUser()?->getUID() !== $uid) {
			if (!$this->switched) {
				$this->previousUser = $this->userSession->getUser();
				$this->switched = true;
			}
			$this->contextUid = null;
			try {
				$this->tearDownFilesystem();
				$this->userSession->setVolatileActiveUser($user);
				$this->setupFilesystem($uid);
			} catch (Throwable $e) {
				$this->logger->error('folder_retention: filesystem could not be set up', ['exception' => $e, 'user' => $uid]);
				return $this->contextFailed[$uid] = $this->t('Filesystem for %1$s cannot be set up: %2$s', [$uid, $e->getMessage()]);
			}
			$this->contextUid = $uid;
		}

		// Sicherheitsgurt: files_trashbin verlässt sich auf genau diese Sicht
		$viewRoot = $this->viewRoot();
		if ($viewRoot !== "/$uid/files" || $this->userSession->getUser()?->getUID() !== $uid) {
			$this->contextUid = null;
			$this->logger->error('folder_retention: filesystem context for ' . $uid . ' cannot be verified (view: ' . ($viewRoot ?? 'none') . ') – deletion refused');
			return $this->contextFailed[$uid] = $this->t('Filesystem context for %1$s cannot be verified (view: %2$s) – deletion refused', [$uid, $viewRoot ?? $this->t('none')]);
		}
		return null;
	}

	/**
	 * Den Knoten der Datei im Home des Besitzers bzw. im Team-Ordner-Mount – nie über eine Freigabe.
	 * Löschen über einen Freigabe-Mount entfernt nur die Freigabe, nicht die Datei.
	 */
	private function findNode(RetentionRoot $root, string $uid, FileRow $file): ?File {
		foreach ($this->rootFolder->getUserFolder($uid)->getById($file->fileId) as $node) {
			if (!$node instanceof File || $node->getId() !== $file->fileId) {
				continue;
			}
			$storage = $node->getStorage();
			if ($storage->instanceOfStorage(ISharedStorage::class)) {
				continue;
			}
			if ($storage->getCache()->getNumericStorageId() !== $file->storageId) {
				continue;
			}
			$provider = $node->getMountPoint()->getMountProvider();
			$fits = $root->isAccount()
				? $provider !== '' && is_a($provider, IHomeMountProvider::class, true)
				: $provider === RootProvider::GROUPFOLDER_PROVIDER;
			if ($fits) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Wo ist die Datei nach dem Löschversuch? Datei-IDs bleiben beim Verschieben in den
	 * Papierkorb erhalten; ist die ID aus dem Filecache verschwunden, ist die Datei endgültig weg –
	 * außer im Team-Ordner, dessen Papierkorb sie unter neuer ID führt (copiedToGroupTrash).
	 *
	 * @return string 'trash' | 'gone' | 'unchanged' | interner Pfad an unerwartetem Ort
	 */
	private function whereIsFile(RetentionRoot $root, FileRow $file): string {
		$entry = $this->fileCache->getEntry($file->fileId);
		if ($entry === null) {
			return !$root->isAccount() && $this->copiedToGroupTrash($root, $file) ? 'trash' : 'gone';
		}
		if ($entry['storage'] === $file->storageId && $entry['path'] === $file->path) {
			return 'unchanged';
		}
		if ($root->isAccount()) {
			if ($entry['storage'] === $root->storageId && str_starts_with($entry['path'], 'files_trashbin/files/')) {
				return 'trash';
			}
		} else {
			$inTrash = $this->fileCache->isInGroupFolderTrash($file->fileId);
			// Ohne file_id-Spalte (ältere groupfolders): Papierkorb liegt unter …/trash/…
			if ($inTrash ?? (bool)preg_match('#(^|/)trash/#', $entry['path'])) {
				return 'trash';
			}
		}
		return $entry['storage'] . ':' . $entry['path'];
	}

	/**
	 * Team-Ordner mit groupfolders-Verschlüsselung: groupfolders kopiert die Datei in den
	 * Papierkorb (Encryption::copyBetweenStorage) – dort steht sie unter einer NEUEN Datei-ID,
	 * oc_group_folders_trash nennt weiter die alte. Liegt unter Name und Zeitpunkt dieses
	 * Eintrags eine Datei im Papierkorb, die keinem anderen Papierkorb-Eintrag gehört, ist sie dort.
	 */
	private function copiedToGroupTrash(RetentionRoot $root, FileRow $file): bool {
		$trashed = $this->fileCache->groupFolderTrashEntry($file->fileId);
		if ($trashed === null) {
			return false;
		}
		[, $storageId, $path] = $this->trashArea($root);
		$id = $this->fileCache->getIdByPath($storageId, $path . '/' . $trashed['name'] . '.d' . $trashed['time']);
		// Gehört die ID dort einem anderen Eintrag, hat eine andere Datei den Platz belegt
		return $id !== null && $this->fileCache->isInGroupFolderTrash($id) === false;
	}

	/**
	 * Nach dem Lauf: Dateisystem abbauen und den vorherigen Benutzer wiederherstellen.
	 */
	public function releaseContext(): void {
		// Prozess mit unpausiertem Papierkorb an Folgejobs übergeben (NC 34: kein finally in moveToTrash)
		try {
			$this->resumeTrash();
		} catch (Throwable $e) {
			$this->logger->warning('folder_retention: trash bin could not be resumed', ['exception' => $e]);
		}
		if (!$this->switched) {
			$this->resetRunState();
			return;
		}
		try {
			$this->tearDownFilesystem();
		} catch (Throwable $e) {
			$this->logger->warning('folder_retention: filesystem teardown failed', ['exception' => $e]);
		}
		$this->userSession->setVolatileActiveUser($this->previousUser);
		$this->switched = false;
		$this->previousUser = null;
		$this->contextUid = null;
		$this->contextFailed = [];
		$this->resetRunState();
	}

	private function resetRunState(): void {
		$this->halted = null;
		$this->trashBase = [];
		$this->trashAdded = [];
		$this->trashed = [];
		$this->verifiedTrash = [];
		$this->lost = [];
		$this->lostIds = [];
	}

	/** Ab dieser Namenslänge (Byte) gelten alle Namen eines Papierkorbs als möglicherweise gleich */
	private const LONG_NAME_BYTES = 220;
	private const LONG_NAME_KEY = '/lang'; // kein Dateiname enthält „/“

	/**
	 * Schlüssel für Namenskollisionen im Papierkorb (klein geschrieben – vorsichtshalber).
	 *
	 * files_trashbin kürzt „<name>.d<Zeit>“ über 250 Byte in der Mitte (Trashbin::getTrashFilename):
	 * Zwei Namen, die sich nur im herausgeschnittenen Stück unterscheiden, ergeben denselben
	 * Eintrag. Daher der Name so, wie Nextcloud ihn bildet (Zeit zehnstellig wie time() bis 2286),
	 * und für lange Namen zusätzlich ein gemeinsamer Schlüssel: Versionen heißen
	 * „<name>.v<Version>.d<Zeit>“ und werden schon bei kürzeren Namen gekürzt, groupfolders
	 * kürzt womöglich anders. Nach jeder langen Datei also eine neue Sekunde abwarten.
	 *
	 * @return list<string>
	 */
	private function trashNameKeys(FileRow $file): array {
		$name = basename($file->path);
		$keys = [mb_strtolower(substr(self::trashFilename($name, 1_000_000_000), 0, -12))];
		if (strlen($name) >= self::LONG_NAME_BYTES) {
			$keys[] = self::LONG_NAME_KEY;
		}
		return $keys;
	}

	/** Wie Trashbin::getTrashFilename (NC 34/35) – Länge in Byte, gekürzt wird nach Zeichen */
	private static function trashFilename(string $filename, int $timestamp): string {
		$trashFilename = $filename . '.d' . $timestamp;
		$length = strlen($trashFilename);
		$maxLength = 250;
		if ($length <= $maxLength) {
			return $trashFilename;
		}
		$charsToRemove = $length - $maxLength + 1;
		$charLength = mb_strlen($trashFilename);
		$start = mb_substr($trashFilename, 0, intdiv($charLength, 2) - $charsToRemove);
		$end = mb_substr($trashFilename, intdiv($charLength, 2));
		return $start . '_' . $end;
	}

	/** Höchstens so oft (je 0,1 s) warten, bis der Papierkorb-Name der aktuellen Sekunde frei ist */
	private const TRASH_NAME_MAX_PAUSES = 100;

	/**
	 * Wurde schon eine gleichnamige Datei in denselben Papierkorb verschoben, bis zur nächsten
	 * Sekunde warten – sonst bekäme sie denselben Namen „<name>.d<time()>“, und Nextcloud
	 * überschriebe den früheren Eintrag endgültig.
	 *
	 * Zwei Quellen: die Löschungen dieses Laufs (auch kurze Namen mit anderer Schreibweise) und
	 * der Filecache. Letzterer kennt auch Einträge eines anderen Laufs, der eben erst die
	 * Laufsperre freigegeben hat – z. B. zwei occ-Läufe direkt hintereinander, oder der Job
	 * übernimmt die Sperre in derselben Sekunde. Der Speicher dieses Laufs weiß davon nichts.
	 *
	 * @return string|null Grund, warum nicht gelöscht wird (Name bleibt belegt); null = frei
	 */
	private function awaitFreshTrashSecond(RetentionRoot $root, FileRow $file): ?string {
		$last = null;
		$area = $this->trashArea($root)[0];
		foreach ($this->trashNameKeys($file) as $name) {
			foreach ($this->trashed[$area][$name] ?? [] as $t) {
				$last = max($last ?? $t['at'], $t['at']);
			}
		}
		if ($last !== null) {
			while ($this->now() <= $last) {
				$this->pause();
			}
		}
		for ($i = 0; $this->trashNameTaken($root, $file, $this->now()); $i++) {
			if ($i >= self::TRASH_NAME_MAX_PAUSES) {
				return $this->t('Trash bin name “%s.d…” of the current second is permanently taken (clock?) – Nextcloud would overwrite the existing entry; not deleted', [basename($file->path)]);
			}
			$this->pause();
		}
		return null;
	}

	/**
	 * Liegt im Papierkorb des Bereichs schon ein Eintrag mit dem Namen, den Nextcloud dieser
	 * Datei in Sekunde $time gäbe? files_trashbin: „files_trashbin/files/<getTrashFilename>“,
	 * groupfolders: „<Papierkorb>/<name>.d<time>“. Lange Namen kürzt Nextcloud womöglich anders
	 * (Versionen, groupfolders) – dort zählt vorsichtshalber jeder Eintrag dieser Sekunde.
	 */
	private function trashNameTaken(RetentionRoot $root, FileRow $file, int $time): bool {
		[, $storageId, $path] = $this->trashArea($root);
		$dir = $root->isAccount() ? $path . '/files' : $path;
		$name = basename($file->path);
		$entry = $root->isAccount() ? self::trashFilename($name, $time) : $name . '.d' . $time;
		if ($this->fileCache->getIdByPath($storageId, $dir . '/' . $entry) !== null) {
			return true;
		}
		return strlen($name) >= self::LONG_NAME_BYTES && $this->fileCache->hasTrashEntryAt($storageId, $dir, $time);
	}

	private function rememberTrashed(RetentionRoot $root, FileRow $file, bool $verified): void {
		// Sekunde NACH dem Löschen: mindestens die, die Nextcloud im Namen verwendet hat
		$entry = ['at' => $this->now(), 'root' => $root, 'file' => $file, 'verified' => $verified];
		foreach ($this->trashNameKeys($file) as $name) {
			$this->trashed[$this->trashArea($root)[0]][$name][] = $entry;
		}
		if ($verified) {
			$this->verifiedTrash[$file->fileId] = ['from' => $this->attemptAt, 'at' => $entry['at'], 'root' => $root, 'file' => $file];
		}
	}

	/**
	 * Sicherheitsgurt nach jedem Löschversuch: Liegen die gleichnamigen, in diesem Lauf in
	 * denselben Papierkorb verschobenen Dateien noch dort? Fehlt eine, hat Nextcloud ihren Eintrag
	 * überschrieben – sie ist endgültig weg. Dann Lauf anhalten und den Verlust melden (takeLost).
	 *
	 * Überschreiben kann Nextcloud nur einen Eintrag derselben Sekunde. Nachgeprüft werden daher
	 * nur Einträge, die frühestens in der Sekunde vor diesem Löschversuch ($attemptAt) entstanden
	 * sind (eine Sekunde Spiel). Ältere fallen aus der Liste: Fehlt so ein Eintrag, hat ihn der
	 * Nutzer geleert oder der Papierkorb abgelaufen – das ist kein Verlust durch diese App.
	 */
	private function checkEarlierTrashEntries(RetentionRoot $root, FileRow $file, int $attemptAt): void {
		$area = $this->trashArea($root)[0];
		/** @var array<int, string> Datei-ID → Befund dieses Aufrufs (eine Datei steht unter mehreren Schlüsseln) */
		$seen = [];
		foreach ($this->trashNameKeys($file) as $name) {
			$kept = [];
			foreach ($this->trashed[$area][$name] ?? [] as $t) {
				if ($t['at'] < $attemptAt - 1) {
					continue; // andere Sekunde – kann diesen Namen nicht bekommen haben
				}
				$id = $t['file']->fileId;
				$where = $seen[$id] ??= !$t['verified'] || $id === $file->fileId ? 'trash' : $this->whereIsFile($t['root'], $t['file']);
				if ($where === 'trash') {
					$kept[] = $t;
					continue;
				}
				if ($where !== 'gone' || isset($this->lostIds[$id])) {
					continue; // inzwischen wiederhergestellt o. Ä. bzw. schon gemeldet
				}
				$this->markLost($t['root'], $t['file'], $this->t('Trash bin entry disappeared later – when the file “%s” was moved (same name in the trash bin), Nextcloud overwrote it; file permanently deleted', [$root->displayPath($file->path)]));
			}
			if (isset($this->trashed[$area][$name])) {
				$this->trashed[$area][$name] = $kept;
			}
		}
	}

	/** Verlust vormerken (takeLost), Nextcloud-Log, Lauf anhalten */
	private function markLost(RetentionRoot $root, FileRow $file, string $reason): void {
		$this->lostIds[$file->fileId] = true;
		$this->lost[] = [$root, $file, $reason];
		$this->logger->error('folder_retention: file disappeared from the trash bin – permanently deleted', [
			'fileId' => $file->fileId, 'path' => $file->path, 'root' => $root->label, 'reason' => $reason,
		]);
		$this->halted ??= $this->t('Trash bin entry of a previously moved file disappeared (file ID %s)', [(string)$file->fileId]);
	}

	/**
	 * Sicherheitsgurt am Laufende (vor takeLost/releaseContext): Löscht ein Nutzer in derselben
	 * Sekunde eine gleichnamige Datei, überschreibt Nextcloud den Eintrag der App – und danach
	 * sieht checkEarlierTrashEntries nicht mehr nach, wenn die App diesen Namen nicht noch einmal
	 * löscht. Daher eine volle Sekunde nach der letzten verbuchten Löschung abwarten und jede in
	 * diesem Lauf verbuchte Löschung genau einmal nachprüfen. Fehlt ein Eintrag, meldet takeLost ihn:
	 * - aus den letzten zwei Sekunden immer (kurz nach dem Verschieben kann es nur Überschreiben
	 *   oder sofortiges endgültiges Löschen gewesen sein),
	 * - ältere nur, wenn unter ihrem Namen und ihrer Sekunde jetzt ein anderer Eintrag liegt –
	 *   dann hat Nextcloud ihn überschrieben. Liegt dort nichts, kann ebenso der Nutzer den
	 *   Papierkorb geleert haben oder der Eintrag abgelaufen sein.
	 */
	public function verifyRecentTrash(): void {
		$entries = $this->verifiedTrash;
		$this->verifiedTrash = [];
		if ($entries === []) {
			return;
		}
		$last = max(array_map(fn (array $t) => $t['at'], $entries));
		while ($this->now() <= $last + 1) {
			$this->pause();
		}
		foreach ($entries as $id => $t) {
			if (isset($this->lostIds[$id]) || $this->whereIsFile($t['root'], $t['file']) !== 'gone') {
				continue;
			}
			if ($t['at'] >= $last - 1) {
				$this->markLost($t['root'], $t['file'], $this->t('Trash bin entry disappeared shortly after the move – probably overwritten by a deletion with the same name in the same second (Nextcloud) or immediately deleted permanently from the trash bin; file permanently deleted'));
				continue;
			}
			for ($sec = $t['from']; $sec <= $t['at']; $sec++) {
				if ($this->trashNameTaken($t['root'], $t['file'], $sec)) {
					$this->markLost($t['root'], $t['file'], $this->t('Trash bin entry disappeared, another entry of the same second now has its name – overwritten by a deletion with the same name (Nextcloud); file permanently deleted'));
					break;
				}
			}
		}
	}

	/**
	 * Seit dem letzten Aufruf entdeckte Dateien, die als gelöscht galten, deren Papierkorb-Eintrag
	 * aber verloren ging – der Aufrufer korrigiert das Protokoll und sperrt den Bereich.
	 *
	 * @return list<array{0: RetentionRoot, 1: FileRow, 2: string}>
	 */
	public function takeLost(): array {
		$lost = $this->lost;
		$this->lost = [];
		return $lost;
	}

	// Private Nextcloud-API und Umgebung, gekapselt (in Unit-Tests ersetzt)

	/** System-Cron oder occ – nicht cron.php im Web (AJAX/Webcron) */
	protected function isCli(): bool {
		return PHP_SAPI === 'cli';
	}

	/** Trägt die laufende Anfrage „X-NC-Skip-Trashbin: true“? files_trashbin löscht dann endgültig. */
	protected function requestSkipsTrashbin(): bool {
		try {
			return \OCP\Server::get(\OCP\IRequest::class)->getHeader('X-NC-Skip-Trashbin') === 'true';
		} catch (Throwable) {
			return false; // ohne Anfrage-Objekt (CLI ohne Request) gibt es auch keine Kopfzeile
		}
	}

	protected function now(): int {
		return time();
	}

	protected function pause(): void {
		usleep(100_000);
	}

	protected function tearDownFilesystem(): void {
		\OC_Util::tearDownFS();
	}

	protected function setupFilesystem(string $uid): void {
		\OC_Util::setupFS($uid);
		$this->rootFolder->getUserFolder($uid);
	}

	/**
	 * Setzt TrashManager::$trashPaused zurück. Bleibt es nach einer Ausnahme im Papierkorb-Backend
	 * hängen, liefert moveToTrash sofort false – und der Speicher-Wrapper löscht endgültig.
	 */
	protected function resumeTrash(): void {
		if (!interface_exists(\OCA\Files_Trashbin\Trash\ITrashManager::class)) {
			return; // files_trashbin nicht geladen – dann gibt es auch keinen pausierten Papierkorb
		}
		\OCP\Server::get(\OCA\Files_Trashbin\Trash\ITrashManager::class)->resumeTrash();
	}

	/** Wurzel von Filesystem::getView() – die Sicht, über die files_trashbin den Papierkorb findet */
	protected function viewRoot(): ?string {
		return \OC\Files\Filesystem::getView()?->getRoot();
	}
}
