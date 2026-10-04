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
 * Moves a file to the trash bin (files_trashbin or the team folder trash bin).
 * Permanent deletion is never intended: without a trash bin that demonstrably fits, deletion is
 * refused, and after deleting we check whether the file actually arrived there.
 *
 * Why the effort: files_trashbin stores the file via Filesystem::getView() – that is the view of
 * the account for which the filesystem was set up first. If it does not match the file, the move
 * fails and the storage wrapper SILENTLY deletes permanently (files_trashbin/lib/Storage.php,
 * doDelete). The same happens when the trash bin is not enabled for the logged-in user – in cron
 * there is none, so always when files_trashbin is restricted to groups –, for files above the
 * maximum trash bin size, and for *.part.
 * Therefore: switch the filesystem and the active user to exactly the account that owns the file.
 *
 * Consequence: Nextcloud considers this account the deleting user – trash bin ("Deleted by" in the
 * team folder), activity and admin_audit name the owner or a team folder member.
 * So this can be attributed to the app, the account is stated in the log entry ("via account ..."),
 * and for team folders it is consistently the first suitable member in sorted order.
 *
 * Further ways past the trash bin:
 * - Purging for space: after the move, files_trashbin (expire job) permanently purges entries
 *   until trashbin_size or 50 % of the free quota space suffices again; groupfolders empties the
 *   team folder trash bin as soon as content + trash bin exceed the quota. Calculate this
 *   beforehand, including this run's moves and the versions that move along
 *   (files_trashbin/versions).
 * - Exception in the trash bin backend: TrashManager::moveToTrash then does not reset trashPaused,
 *   and EVERY further deletion in the same process would be permanent. Hence resumeTrash() before
 *   every attempt, and after an exception while deleting, nothing more for the rest of the run.
 *   Also resumeTrash() after the exception and during cleanup: cron.php processes further jobs in
 *   the same process, whose deletions would otherwise be permanent too.
 *   In addition, LegacyTrashBackend remembers the path (deletedFiles) and does not clear it after
 *   an exception – a later attempt at the same path immediately returns false, and files_trashbin
 *   deletes permanently. A long-lived process (occ background-job:worker) runs the RetentionJob
 *   multiple times: these paths therefore stay blocked for the rest of the process.
 * - Same name in the same second: files_trashbin and groupfolders name the entry
 *   "<name>.d<time()>" and permanently overwrite an existing target (including versions). Hence,
 *   per trash bin and name, wait until a new second has begun – based on this run's deletions and
 *   on the file cache (entries of another run that just finished) –, and after every deletion
 *   check whether this run's same-named entries from the same second are still there.
 *   "Same-named" as in the
 *   trash bin: files_trashbin shortens long names in the middle (trashNameKeys).
 *   A user can also delete a same-named file in the same second and overwrite the app's entry
 *   (Trashbin::move2trash: name lock already released, then unlink). This is only noticed if the
 *   app checks again later – hence verifyRecentTrash() at the end of the run.
 * - Header "X-NC-Skip-Trashbin: true": files_trashbin then deletes bypassing the trash bin. In
 *   web cron (AJAX/Webcron) the job runs in an anonymous request to cron.php – anyone could set
 *   the header. Therefore only delete in CLI context (system cron, occ).
 */
class Deleter {
	/** Account whose filesystem is currently set up; null = not switched yet */
	private ?string $contextUid = null;
	private bool $switched = false;
	private ?IUser $previousUser = null;
	/** @var array<string, string> uid → reason why its context is not usable in this run */
	private array $contextFailed = [];
	/** set = the trash bin threw an exception in this run, delete nothing more */
	private ?string $halted = null;
	/**
	 * Process-wide, survives releaseContext(): "storage:path" of files whose deletion threw an
	 * exception – the trash bin may consider them already moved (LegacyTrashBackend).
	 *
	 * @var array<string, true>
	 */
	private static array $unreliablePaths = [];
	/** @var array<string, int|float> trash bin → size at first look in this run */
	private array $trashBase = [];
	/** @var array<string, int|float> trash bin → bytes moved into it in this run */
	private array $trashAdded = [];
	/**
	 * Files moved to the trash bin in this run, per trash bin and name
	 * (name as in the trash bin, lowercased): second of deletion and file.
	 *
	 * verified = recorded as "deleted" (only these are re-checked by the safety net).
	 *
	 * @var array<string, array<string, list<array{at: int, root: RetentionRoot, file: FileRow, verified: bool}>>>
	 */
	private array $trashed = [];
	/**
	 * All files recorded as "deleted" in this run, for verifyRecentTrash (unlike $trashed never
	 * pruned early): file ID → seconds before (from) and after (at) the deletion.
	 *
	 * @var array<int, array{from: int, at: int, root: RetentionRoot, file: FileRow}>
	 */
	private array $verifiedTrash = [];
	/** @var list<array{0: RetentionRoot, 1: FileRow, 2: string}> lost trash bin entries (area, file, reason) */
	private array $lost = [];
	/** @var array<int, true> file IDs whose loss has already been reported */
	private array $lostIds = [];
	/** was File::delete() called in the current attempt? */
	private bool $attempted = false;
	/** second immediately before File::delete() in the current attempt */
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
	 * @return array{0: string, 1: ?string} [LogEntry::STATUS_*, message]
	 */
	public function delete(RetentionRoot $root, FileRow $file): array {
		if ($this->halted !== null) {
			return [LogEntry::STATUS_ERROR, $this->t('Deletion halted for this run: %s', [$this->halted])];
		}
		if (isset(self::$unreliablePaths[$file->storageId . ':' . $file->path])) {
			return [LogEntry::STATUS_ERROR, $this->t('Trash bin state of this process is unreliable (earlier exception while deleting at this path) – only after a process restart; not deleted')];
		}
		if (str_ends_with(strtolower($file->path), '.part')) {
			// Nextcloud treats *.part as upload leftovers and deletes them bypassing the trash bin
			return [LogEntry::STATUS_ERROR, $this->t('Extension .part – Nextcloud would delete the file permanently, bypassing the trash bin; not deleted')];
		}
		if (!$this->isCli()) {
			// Web cron: anonymous request, X-NC-Skip-Trashbin could be set from outside, max_execution_time
			// could abort between deleting and logging
			return [LogEntry::STATUS_ERROR, $this->t('Deleting only with system cron or occ (background jobs run via AJAX/Webcron) – not deleted')];
		}

		$this->attempted = false;
		$result = $this->attemptDelete($root, $file);
		if ($this->attempted) {
			$this->checkEarlierTrashEntries($root, $file, $this->attemptAt);
			// remember it even without proof: if it is in the trash bin after all, it occupies this second's name
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
			// Versions move along into the trash bin on deletion and count there too
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

			// Wait first, then check: there must be no waiting time between the last check (location,
			// mtime) and the deletion during which a sync client could replace the file
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
				// getById also finds the file at a new location – between the re-check and here
				// (account switch) it may have been moved, with the same ID and mtime
				$entry = $this->fileCache->getEntry($file->fileId);
				if ($entry === null || $entry['storage'] !== $file->storageId || $entry['path'] !== $file->path) {
					return [LogEntry::STATUS_SKIPPED_CHANGED, $this->t('File was moved since it was evaluated')];
				}
				// New content with the old mtime (client sets it on upload): size or etag differ.
				// From the file cache, not from the node – with encryption the node reports the
				// unencrypted size
				if ($entry['size'] !== $file->size || $entry['etag'] !== $file->etag) {
					return [LogEntry::STATUS_SKIPPED_CHANGED, $this->t('File was modified since it was evaluated')];
				}
				// Without delete permission (group permissions, ACL in the team folder) File::delete throws
				// before the trash bin is touched – try the next member, do not halt the run
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
				// View::unlink (View::lockFile) acquires the lock on the file itself and (shared) on its
				// parent folders before the trash bin gets its turn – e.g. a reading sync client or a
				// folder rename: skip only this file. Locks on other paths may come from the trash bin
				// and still halt the run.
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
				// Exception from the deletion itself: the trash bin state of this process is unreliable.
				// Reset trashPaused anyway – otherwise subsequent jobs in the same process delete permanently
				try {
					$this->resumeTrash();
				} catch (Throwable) {
				}
				$this->halt($error[1], $uid, $file);
				self::$unreliablePaths[$file->storageId . ':' . $file->path] = true;
				$error[1] = $this->t('%s – further deletions halted for this run', [$error[1]]);
			}

			// Check even after an exception: it may be thrown after the removal
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
				// No further candidate: after an exception while deleting, the next attempt might
				// bypass the trash bin
				return [$error[0], mb_substr($error[1], 0, 1000)];
			}
			$lastError = $error[1] ?? $this->t('File still present unchanged after deletion');
		}
		return [LogEntry::STATUS_ERROR, mb_substr($lastError, 0, 1000)];
	}

	/**
	 * Is the locked path the file itself or one of its folders from "/<uid>/files" down? These locks
	 * are acquired by View::lockFile before the trash bin. Not "/<uid>" alone – that is also locked
	 * by view operations in the trash bin (copying versions).
	 */
	private static function lockedBeforeTrash(string $locked, string $nodePath): bool {
		$locked = trim($locked, '/');
		$own = trim($nodePath, '/');
		return $locked === $own || (str_contains($locked, '/') && str_starts_with($own, $locked . '/'));
	}

	/**
	 * Log message for "deleted": trash bin, activity and audit log name this account as the
	 * deleting user – the message makes clear that it was the app.
	 */
	public function deletedVia(string $uid): string {
		return $this->t('Moved to the trash bin via account %1$s (trash bin/activity name %1$s as the deleting user; folder_retention deleted it)', [$uid]);
	}

	/**
	 * Accounts through whose view deletion is allowed. Account areas: only the owner.
	 * Team folders: members in a fixed, sorted order – Nextcloud names the chosen account as the
	 * deleting user, and that should not depend on which context happens to be set up.
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
	 * Reasons for which Nextcloud would delete the file bypassing the trash bin – those detectable in advance.
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
	 * Account trash bin: calculates like files_trashbin (Trashbin::getConfiguredTrashbinSize,
	 * calculateFreeSpace) with the state AFTER the move. If no space is left afterwards, the expire
	 * job permanently purges entries – possibly the very ones just moved.
	 * All of files_trashbin is measured, i.e. including the versions moved along ($versions);
	 * usage as there via the account root (with object storage including trash bin and versions).
	 */
	private function accountTrashBlocker(RetentionRoot $root, IUser $user, FileRow $file, int|float $versions): ?string {
		$uid = $user->getUID();
		// Maximum size (trashbin_size): account value takes precedence over app value
		$limit = $this->config->getUserValue($uid, 'files_trashbin', 'trashbin_size', '-1');
		if (!is_numeric($limit) || (float)$limit <= -1) {
			try {
				$limit = $this->appConfig->getValueString('files_trashbin', 'trashbin_size', '-1');
			} catch (AppConfigTypeConflictException) {
				// occ trashbin:size stores a number, files_trashbin reads text – every move to the
				// trash bin then fails with an exception
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
			return null; // without a quota files_trashbin only purges when the disk is full
		}
		$quotaBytes = Util::computerFileSize($quota);
		if ($quotaBytes === false || $quotaBytes < 0) {
			return null; // files_trashbin treats an invalid quota as unlimited
		}
		// Usage as in files_trashbin: size of the account root, after the move
		$files = $this->fileCache->getSize($root->storageId, 'files');
		$files = $files === null || $files < 0 ? 0 : $files;
		if ($this->homeCacheCountsFilesOnly($root->storageId)) {
			// Local storage (Storage\Home with HomeCache): the root reports the size of files/,
			// and the file is no longer in it afterwards. The root row in the file cache is
			// meaningless here (0 or outdated) and is left out.
			$used = max(0, $files - $size);
		} else {
			// Object storage (HomeObjectStoreStorage, normal cache): the root row counts
			// files/, trash bin and versions – the file stays in it after the move.
			// Same for an unknown storage type: better to overestimate usage than underestimate it.
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
	 * Does Nextcloud calculate this account's usage from files/ only (HomeCache)? That applies to
	 * the local home storage ("home::<uid>", older installations "local::<path>"). Object storage as
	 * primary storage is called "object::user:<uid>" and uses the normal cache.
	 */
	private function homeCacheCountsFilesOnly(int $storageId): bool {
		$id = $this->fileCache->storageStringId($storageId);
		return $id !== null && (str_starts_with($id, 'home::') || str_starts_with($id, 'local::'));
	}

	private function versionsNote(int|float $versions): string {
		return $versions > 0 ? $this->t(', including moved versions %s', [Util::humanFileSize((int)$versions)]) : '';
	}

	/**
	 * Size of the versions that files_trashbin moves into the trash bin along with the file
	 * (Trashbin::retainVersions, only with files_versions enabled). Team folders: groupfolders
	 * leaves versions in place and does not count them when purging.
	 *
	 * @return int|float|null null = unknown
	 */
	private function versionsSize(RetentionRoot $root, IUser $user, FileRow $file): int|float|null {
		if (!$root->isAccount() || !$this->appManager->isEnabledForUser('files_versions', $user)) {
			return 0;
		}
		return $this->fileCache->versionsSize($root->storageId, $file->path);
	}

	/**
	 * Team folder: groupfolders (ExpireGroupTrash, hourly) empties the trash bin as long as
	 * content + trash bin exceed the quota. The move does not change the sum –
	 * if it is already too large, this file too would soon be permanently gone.
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
			return null; // unlimited
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
	 * Trash bin of an area: [key, storage, internal path].
	 * Team folder: "__groupfolders/<id>" → "__groupfolders/trash/<id>"; with a separate storage per
	 * team folder, files are under "files" and the trash bin under "trash".
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
	 * Current trash bin size – at least the state at first look plus what was moved in this run
	 * (in case Nextcloud has not updated the folder size yet).
	 */
	private function trashUsed(RetentionRoot $root): int|float {
		[$key, $storageId, $path] = $this->trashArea($root);
		$read = $this->fileCache->getSize($storageId, $path);
		$read = $read === null || $read < 0 ? 0 : $read;
		$this->trashBase[$key] ??= $read;
		return max($read, $this->trashBase[$key] + ($this->trashAdded[$key] ?? 0));
	}

	/** Delete nothing more for the rest of the run; warning to the Nextcloud log */
	private function halt(string $reason, string $uid, FileRow $file): void {
		$this->halted ??= mb_substr($reason, 0, 500);
		$this->logger->warning('folder_retention: exception while moving to the trash bin – further deletions halted for this run, as Nextcloud might bypass the trash bin afterwards', [
			'fileId' => $file->fileId, 'path' => $file->path, 'user' => $uid, 'reason' => $reason,
		]);
	}

	/** Reason why nothing more is deleted in this run; null = all fine */
	public function haltReason(): ?string {
		return $this->halted;
	}

	/**
	 * Switch the filesystem and active user to $user – only if not already done.
	 *
	 * @return string|null error message if the context is not demonstrably correct
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

		// Safety net: files_trashbin relies on exactly this view
		$viewRoot = $this->viewRoot();
		if ($viewRoot !== "/$uid/files" || $this->userSession->getUser()?->getUID() !== $uid) {
			$this->contextUid = null;
			$this->logger->error('folder_retention: filesystem context for ' . $uid . ' cannot be verified (view: ' . ($viewRoot ?? 'none') . ') – deletion refused');
			return $this->contextFailed[$uid] = $this->t('Filesystem context for %1$s cannot be verified (view: %2$s) – deletion refused', [$uid, $viewRoot ?? $this->t('none')]);
		}
		return null;
	}

	/**
	 * The file's node in the owner's home or in the team folder mount – never via a share.
	 * Deleting via a share mount only removes the share, not the file.
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
	 * Where is the file after the deletion attempt? File IDs are preserved when moving to the
	 * trash bin; if the ID has disappeared from the file cache, the file is permanently gone –
	 * except in a team folder, whose trash bin may hold it under a new ID (copiedToGroupTrash).
	 *
	 * @return string 'trash' | 'gone' | 'unchanged' | internal path at an unexpected location
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
			// Without a file_id column (older groupfolders): the trash bin is under .../trash/...
			if ($inTrash ?? (bool)preg_match('#(^|/)trash/#', $entry['path'])) {
				return 'trash';
			}
		}
		return $entry['storage'] . ':' . $entry['path'];
	}

	/**
	 * Team folder with groupfolders encryption: groupfolders copies the file into the trash bin
	 * (Encryption::copyBetweenStorage) – there it has a NEW file ID, while oc_group_folders_trash
	 * still names the old one. If a file in the trash bin sits under this entry's name and time and
	 * belongs to no other trash bin entry, the file is there.
	 */
	private function copiedToGroupTrash(RetentionRoot $root, FileRow $file): bool {
		$trashed = $this->fileCache->groupFolderTrashEntry($file->fileId);
		if ($trashed === null) {
			return false;
		}
		[, $storageId, $path] = $this->trashArea($root);
		$id = $this->fileCache->getIdByPath($storageId, $path . '/' . $trashed['name'] . '.d' . $trashed['time']);
		// If the ID there belongs to another entry, a different file has taken the spot
		return $id !== null && $this->fileCache->isInGroupFolderTrash($id) === false;
	}

	/**
	 * After the run: tear down the filesystem and restore the previous user.
	 */
	public function releaseContext(): void {
		// Hand the process over to subsequent jobs with the trash bin unpaused (NC 34: no finally in moveToTrash)
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

	/** From this name length (bytes) on, all names in a trash bin are considered possibly equal */
	private const LONG_NAME_BYTES = 220;
	private const LONG_NAME_KEY = '/lang'; // no file name contains "/"

	/**
	 * Keys for name collisions in the trash bin (lowercased – as a precaution).
	 *
	 * files_trashbin shortens "<name>.d<time>" above 250 bytes in the middle (Trashbin::getTrashFilename):
	 * two names that differ only in the cut-out part yield the same entry. Hence the name as
	 * Nextcloud builds it (time with ten digits like time() until 2286), plus a shared key for long
	 * names: versions are named "<name>.v<version>.d<time>" and get shortened even for shorter
	 * names, and groupfolders may shorten differently. So after every long file, wait for a new second.
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

	/** Like Trashbin::getTrashFilename (NC 34/35) – length in bytes, shortened by characters */
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

	/** Wait at most this many times (0.1 s each) until the trash bin name of the current second is free */
	private const TRASH_NAME_MAX_PAUSES = 100;

	/**
	 * If a same-named file has already been moved into the same trash bin, wait until the next
	 * second – otherwise it would get the same name "<name>.d<time()>", and Nextcloud would
	 * permanently overwrite the earlier entry.
	 *
	 * Two sources: this run's deletions (including short names with different casing) and the
	 * file cache. The latter also knows entries of another run that has only just released the
	 * run lock – e.g. two occ runs right after each other, or the job takes over the lock in the
	 * same second. This run's memory knows nothing about those.
	 *
	 * @return string|null reason why it is not deleted (name stays taken); null = free
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
	 * Is there already an entry in the area's trash bin with the name Nextcloud would give this
	 * file in second $time? files_trashbin: "files_trashbin/files/<getTrashFilename>",
	 * groupfolders: "<trash bin>/<name>.d<time>". Nextcloud may shorten long names differently
	 * (versions, groupfolders) – there, as a precaution, every entry of this second counts.
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
		// Second AFTER the deletion: at least the one Nextcloud used in the name
		$entry = ['at' => $this->now(), 'root' => $root, 'file' => $file, 'verified' => $verified];
		foreach ($this->trashNameKeys($file) as $name) {
			$this->trashed[$this->trashArea($root)[0]][$name][] = $entry;
		}
		if ($verified) {
			$this->verifiedTrash[$file->fileId] = ['from' => $this->attemptAt, 'at' => $entry['at'], 'root' => $root, 'file' => $file];
		}
	}

	/**
	 * Safety net after every deletion attempt: are the same-named files moved into the same trash
	 * bin in this run still there? If one is missing, Nextcloud overwrote its entry – it is
	 * permanently gone. Then halt the run and report the loss (takeLost).
	 *
	 * Nextcloud can only overwrite an entry of the same second. Therefore only entries created no
	 * earlier than the second before this deletion attempt ($attemptAt) are re-checked (one second
	 * of slack). Older ones drop out of the list: if such an entry is missing, the user emptied it
	 * or the trash bin expired it – that is not a loss caused by this app.
	 */
	private function checkEarlierTrashEntries(RetentionRoot $root, FileRow $file, int $attemptAt): void {
		$area = $this->trashArea($root)[0];
		/** @var array<int, string> file ID → finding of this call (a file is listed under several keys) */
		$seen = [];
		foreach ($this->trashNameKeys($file) as $name) {
			$kept = [];
			foreach ($this->trashed[$area][$name] ?? [] as $t) {
				if ($t['at'] < $attemptAt - 1) {
					continue; // different second – cannot have received this name
				}
				$id = $t['file']->fileId;
				$where = $seen[$id] ??= !$t['verified'] || $id === $file->fileId ? 'trash' : $this->whereIsFile($t['root'], $t['file']);
				if ($where === 'trash') {
					$kept[] = $t;
					continue;
				}
				if ($where !== 'gone' || isset($this->lostIds[$id])) {
					continue; // restored in the meantime or similar, or already reported
				}
				$this->markLost($t['root'], $t['file'], $this->t('Trash bin entry disappeared later – when the file “%s” was moved (same name in the trash bin), Nextcloud overwrote it; file permanently deleted', [$root->displayPath($file->path)]));
			}
			if (isset($this->trashed[$area][$name])) {
				$this->trashed[$area][$name] = $kept;
			}
		}
	}

	/** Record the loss (takeLost), Nextcloud log, halt the run */
	private function markLost(RetentionRoot $root, FileRow $file, string $reason): void {
		$this->lostIds[$file->fileId] = true;
		$this->lost[] = [$root, $file, $reason];
		$this->logger->error('folder_retention: file disappeared from the trash bin – permanently deleted', [
			'fileId' => $file->fileId, 'path' => $file->path, 'root' => $root->label, 'reason' => $reason,
		]);
		$this->halted ??= $this->t('Trash bin entry of a previously moved file disappeared (file ID %s)', [(string)$file->fileId]);
	}

	/**
	 * Safety net at the end of the run (before takeLost/releaseContext): if a user deletes a
	 * same-named file in the same second, Nextcloud overwrites the app's entry – and afterwards
	 * checkEarlierTrashEntries no longer looks, unless the app deletes that name again.
	 * Hence wait a full second after the last recorded deletion and re-check every deletion
	 * recorded in this run exactly once. If an entry is missing, takeLost reports it:
	 * - from the last two seconds always (shortly after the move it can only have been an
	 *   overwrite or an immediate permanent deletion),
	 * - older ones only if another entry now sits under their name and second –
	 *   then Nextcloud overwrote it. If nothing is there, the user may just as well have emptied
	 *   the trash bin or the entry may have expired.
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
	 * Files discovered since the last call that were considered deleted but whose trash bin entry
	 * was lost – the caller corrects the log and locks the area.
	 *
	 * @return list<array{0: RetentionRoot, 1: FileRow, 2: string}>
	 */
	public function takeLost(): array {
		$lost = $this->lost;
		$this->lost = [];
		return $lost;
	}

	// Private Nextcloud API and environment, encapsulated (replaced in unit tests)

	/** System cron or occ – not cron.php on the web (AJAX/Webcron) */
	protected function isCli(): bool {
		return PHP_SAPI === 'cli';
	}

	/** Does the current request carry "X-NC-Skip-Trashbin: true"? files_trashbin then deletes permanently. */
	protected function requestSkipsTrashbin(): bool {
		try {
			return \OCP\Server::get(\OCP\IRequest::class)->getHeader('X-NC-Skip-Trashbin') === 'true';
		} catch (Throwable) {
			return false; // without a request object (CLI without request) there is no header either
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
	 * Resets TrashManager::$trashPaused. If it stays stuck after an exception in the trash bin
	 * backend, moveToTrash immediately returns false – and the storage wrapper deletes permanently.
	 */
	protected function resumeTrash(): void {
		if (!interface_exists(\OCA\Files_Trashbin\Trash\ITrashManager::class)) {
			return; // files_trashbin not loaded – then there is no paused trash bin either
		}
		\OCP\Server::get(\OCA\Files_Trashbin\Trash\ITrashManager::class)->resumeTrash();
	}

	/** Root of Filesystem::getView() – the view through which files_trashbin finds the trash bin */
	protected function viewRoot(): ?string {
		return \OC\Files\Filesystem::getView()?->getRoot();
	}
}
