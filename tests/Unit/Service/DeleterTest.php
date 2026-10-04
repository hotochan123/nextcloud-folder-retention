<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use OCA\FolderRetention\Db\LogEntry;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\Deleter;
use OCA\FolderRetention\Service\FileCacheReader;
use OCA\FolderRetention\Service\RootProvider;
use OCP\App\IAppManager;
use OCP\Files\Cache\ICache;
use OCP\Files\Config\IHomeMountProvider;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\Files\Storage\IStorageFactory;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\FolderRetention\Tests\Unit\FakeL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../stubs/Emitter.php';

/** Stellvertreter für LocalHomeMountProvider (Deleter prüft per is_a auf IHomeMountProvider) */
final class TestHomeMountProvider implements IHomeMountProvider {
	public function getHomeMountForUser(IUser $user, IStorageFactory $loader) {
		return null;
	}
}

/**
 * Deleter mit Attrappen: Kontowechsel, Sicherheitsgurt, Papierkorb-Vorprüfung, Freigabe-Mounts,
 * Nachweis im Papierkorb. Ob Nextcloud wirklich in den Papierkorb verschiebt, prüft der Harness.
 */
class DeleterTest extends TestCase {
	private IUserSession&MockObject $session;
	private ?IUser $activeUser = null;
	/** von setupFilesystem eingerichtetes Konto → Wurzel von Filesystem::getView() */
	private ?string $viewRoot = null;
	/** Sicherheitsgurt testen: Sicht zeigt trotz Einrichtung auf ein anderes Konto */
	private ?string $forcedViewRoot = null;
	private int $teardowns = 0;
	/** @var list<string> */
	private array $setups = [];
	/** @var array<int, array{storage: int, path: string}|null> fileid → Filecache-Eintrag nach dem Löschen */
	private array $entryAfter = [];
	/** @var array<int, bool> fileid → wurde delete() aufgerufen */
	private array $deleteCalled = [];
	/** @var array<string, list<File>> uid → Knoten, die getById liefert */
	private array $nodes = [];
	/** @var array<string, bool> uid → files_trashbin aktiv */
	private array $trashFor = [];
	private string $trashbinSize = '-1';
	/** trashbin_size mit falschem Typ gespeichert (occ trashbin:size) */
	private bool $trashbinSizeConflict = false;
	private ?bool $groupTrash = null;
	/** @var array<int, bool> fileid → steht in oc_group_folders_trash (vor $groupTrash) */
	private array $groupTrashIds = [];
	/** @var array<int, array{name: string, time: int}> alte fileid → Eintrag in oc_group_folders_trash */
	private array $groupTrashRows = [];
	/** @var array<string, string> uid → Quota wie IUser::getQuota */
	private array $quota = [];
	/** @var array<int, string> Storage → Kennung (oc_storages.id); ohne Eintrag „home::…“ */
	private array $storageIds = [];
	/** @var array<string, int> "storage:pfad" → Größe im Filecache */
	private array $sizes = [];
	private ?int $groupQuota = -3;
	/** @var list<int> fileid → delete() wirft (Papierkorb-Backend kaputt) */
	private array $deleteThrows = [];
	/** @var array<int, string> fileid → delete() wirft LockedException mit diesem Pfad */
	private array $deleteLocked = [];
	/** @var array<string, bool> uid → Knoten ist löschbar (Gruppenrechte/ACL) */
	private array $deletable = [];
	/** @var array<int, int|null> fileid → Größe der Versionen (null = unbekannt) */
	private array $versions = [];
	private bool $versionsApp = true;
	private int $resumes = 0;
	private bool $cli = true;
	private bool $skipHeader = false;
	/** Uhr für Papierkorb-Namen „<name>.d<Sekunde>“; pause() lässt sie eine Sekunde weiterlaufen */
	private int $clock = 100;
	private int $pauses = 0;
	/** @var (callable(): void)|null läuft bei jedem pause() – z. B. Sync-Client ersetzt die Datei */
	private $onPause = null;
	/** @var array<int, int> fileid → mtime, die der Knoten jetzt meldet (Datei inzwischen geändert) */
	private array $mtimeNow = [];
	/** @var array<string, int> "storage:name.dSekunde" → fileid im Papierkorb (wie files_trashbin) */
	private array $trashSlots = [];
	/** @var list<string> Reihenfolge: resume / delete:<id> */
	private array $calls = [];
	/** @var list<string> im Filecache nachgesehene Papierkorb-Pfade „storage:pfad“ */
	private array $trashLookups = [];

	protected function setUp(): void {
		// prozessweiter Merker (übersteht releaseContext) – zwischen Tests leeren
		(new \ReflectionProperty(Deleter::class, 'unreliablePaths'))->setValue(null, []);
	}

	private function deleter(): Deleter&MockObject {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturnCallback(fn () => $this->activeUser);
		$this->session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $u) {
			$this->activeUser = $u;
		});

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid) => in_array($uid, ['alice', 'bob', 'carol'], true) ? $this->user($uid) : null);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app, ?IUser $u) => $u !== null && match ($app) {
			'files_trashbin' => $this->trashFor[$u->getUID()] ?? true,
			'files_versions' => $this->versionsApp,
			default => false,
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('-1');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(function () {
			if ($this->trashbinSizeConflict) {
				throw new AppConfigTypeConflictException('conflict with value type from database');
			}
			return $this->trashbinSize;
		});

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid) {
			$folder = $this->createMock(Folder::class);
			$folder->method('getById')->willReturnCallback(fn (int $id) => array_values(array_filter(
				$this->nodes[$uid] ?? [], fn (File $n) => $n->getId() === $id)));
			return $folder;
		});

		$fileCache = $this->createMock(FileCacheReader::class);
		$fileCache->method('getEntry')->willReturnCallback(function (int $id) {
			$e = $this->entryAfter[$id] ?? null;
			return $e === null ? null : ['fileid' => $id, 'storage' => $e['storage'], 'path' => $e['path'], 'name' => basename($e['path']), 'parent' => 1, 'isFolder' => false, 'mtime' => 5];
		});
		$fileCache->method('isInGroupFolderTrash')->willReturnCallback(fn (int $id) => $this->groupTrashIds[$id] ?? $this->groupTrash);
		$fileCache->method('groupFolderTrashEntry')->willReturnCallback(fn (int $id) => $this->groupTrashRows[$id] ?? null);
		$fileCache->method('getSize')->willReturnCallback(fn (int $storage, string $path) => $this->sizes["$storage:$path"] ?? null);
		$fileCache->method('versionsSize')->willReturnCallback(function (int $storage, string $path) {
			foreach ($this->entryAfter as $id => $e) {
				if ($e !== null && $e['storage'] === $storage && $e['path'] === $path) {
					return array_key_exists($id, $this->versions) ? $this->versions[$id] : 0;
				}
			}
			return 0;
		});
		$fileCache->method('storageStringId')->willReturnCallback(fn (int $storage) => array_key_exists($storage, $this->storageIds) ? $this->storageIds[$storage] : "home::s$storage");
		$fileCache->method('groupFolderQuota')->willReturnCallback(fn () => $this->groupQuota);
		// Papierkorb-Einträge laut Filecache (trashSlots, solange die Datei noch dort liegt)
		$fileCache->method('getIdByPath')->willReturnCallback(function (int $storage, string $path) {
			$this->trashLookups[] = "$storage:$path";
			$id = $this->trashSlots[$storage . ':' . basename($path)] ?? null;
			return $id !== null && $this->entryAfter[$id] !== null ? $id : null;
		});
		$fileCache->method('hasTrashEntryAt')->willReturnCallback(function (int $storage, string $dir, int $time) {
			foreach ($this->trashSlots as $slot => $id) {
				if (str_starts_with($slot, "$storage:") && str_ends_with($slot, ".d$time") && $this->entryAfter[$id] !== null) {
					return true;
				}
			}
			return false;
		});

		$lang = $this->createMock(ContentLanguage::class);
		$lang->method('l10n')->willReturn(FakeL10N::de());

		$deleter = $this->getMockBuilder(Deleter::class)
			->setConstructorArgs([$rootFolder, $apps, $users, $this->session, $config, $appConfig, $fileCache, new NullLogger(), $lang])
			->onlyMethods(['tearDownFilesystem', 'setupFilesystem', 'viewRoot', 'resumeTrash', 'isCli', 'requestSkipsTrashbin', 'now', 'pause'])
			->getMock();
		$deleter->method('isCli')->willReturnCallback(fn () => $this->cli);
		$deleter->method('requestSkipsTrashbin')->willReturnCallback(fn () => $this->skipHeader);
		$deleter->method('now')->willReturnCallback(fn () => $this->clock);
		$deleter->method('pause')->willReturnCallback(function () {
			$this->pauses++;
			$this->clock++;
			if ($this->onPause !== null) {
				($this->onPause)();
			}
		});
		$deleter->method('resumeTrash')->willReturnCallback(function () {
			$this->resumes++;
			$this->calls[] = 'resume';
		});
		$deleter->method('tearDownFilesystem')->willReturnCallback(function () {
			$this->teardowns++;
			$this->viewRoot = null;
		});
		$deleter->method('setupFilesystem')->willReturnCallback(function (string $uid) {
			$this->setups[] = $uid;
			$this->viewRoot = "/$uid/files";
		});
		$deleter->method('viewRoot')->willReturnCallback(fn () => $this->forcedViewRoot ?? $this->viewRoot);
		return $deleter;
	}

	private function user(string $uid): IUser {
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('getQuota')->willReturnCallback(fn () => $this->quota[$uid] ?? 'none');
		return $u;
	}

	private function homeRoot(string $uid, int $storage): RetentionRoot {
		return new RetentionRoot(RetentionRoot::KIND_HOME, $storage, $storage * 10, 'files', 'Persönlich · ' . $uid, [$uid]);
	}

	private function file(int $id, int $storage, string $path, int $size = 10): FileRow {
		return new FileRow($id, $storage, 1, $path, 5, null, 1, $size);
	}

	/**
	 * Knoten in der Sicht von $uid; delete() verschiebt ihn laut $trashPath (null = endgültig weg).
	 */
	private function node(string $uid, FileRow $f, string $provider, ?string $trashPath, bool $shared = false, ?int $storageId = null): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('getNumericStorageId')->willReturn($storageId ?? $f->storageId);
		$storage = $this->createMock(IStorage::class);
		$storage->method('getCache')->willReturn($cache);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $c) => $shared && $c === ISharedStorage::class);
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getMountProvider')->willReturn($provider);

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn($f->fileId);
		$node->method('getMTime')->willReturnCallback(fn () => $this->mtimeNow[$f->fileId] ?? $f->mtime);
		$node->method('getStorage')->willReturn($storage);
		$node->method('getMountPoint')->willReturn($mount);
		$node->method('getPath')->willReturn("/$uid/" . $f->path);
		$node->method('isDeletable')->willReturnCallback(fn () => $this->deletable[$uid] ?? true);
		$node->method('delete')->willReturnCallback(function () use ($f, $trashPath, $shared) {
			$this->deleteCalled[$f->fileId] = true;
			$this->calls[] = 'delete:' . $f->fileId;
			if (in_array($f->fileId, $this->deleteThrows, true)) {
				throw new \RuntimeException('Failed to move Team folder item to trash');
			}
			if (isset($this->deleteLocked[$f->fileId])) {
				throw new \OCP\Lock\LockedException($this->deleteLocked[$f->fileId]);
			}
			if ($shared) {
				return; // nur die Freigabe wäre weg, die Datei bleibt
			}
			$this->entryAfter[$f->fileId] = $trashPath === null ? null : ['storage' => $f->storageId, 'path' => $trashPath];
			if ($trashPath !== null) {
				// wie Trashbin::move2trash: Ziel „<name>.d<time()>“, ein vorhandenes wird endgültig überschrieben
				$slot = $f->storageId . ':' . preg_replace('#\.d\d+$#', '', basename($trashPath)) . '.d' . $this->clock;
				if (isset($this->trashSlots[$slot]) && $this->trashSlots[$slot] !== $f->fileId) {
					$this->entryAfter[$this->trashSlots[$slot]] = null;
				}
				$this->trashSlots[$slot] = $f->fileId;
			}
		});
		$this->nodes[$uid][] = $node;
		$this->entryAfter[$f->fileId] = ['storage' => $f->storageId, 'path' => $f->path];
	}

	public function testDeletesInOwnersContextAndSwitchesOnlyWhenNeeded(): void {
		$deleter = $this->deleter();
		$a1 = $this->file(1, 1, 'files/a1.txt');
		$a2 = $this->file(2, 1, 'files/a2.txt');
		$b1 = $this->file(3, 2, 'files/b1.txt');
		$this->node('alice', $a1, TestHomeMountProvider::class, 'files_trashbin/files/a1.txt.d1');
		$this->node('alice', $a2, TestHomeMountProvider::class, 'files_trashbin/files/a2.txt.d1');
		$this->node('bob', $b1, TestHomeMountProvider::class, 'files_trashbin/files/b1.txt.d1');

		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($this->homeRoot('alice', 1), $a1));
		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($this->homeRoot('alice', 1), $a2));
		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('bob')], $deleter->delete($this->homeRoot('bob', 2), $b1));

		$this->assertSame(['alice', 'bob'], $this->setups, 'Wechsel nur beim Kontowechsel, nicht pro Datei');
		$this->assertSame(2, $this->teardowns, 'vor jedem Wechsel abgebaut');
		$this->assertSame('bob', $this->activeUser?->getUID(), 'aktiver Benutzer = Besitzer (für isEnabledForUser im Papierkorb-Wrapper)');
	}

	public function testRefusesWhenViewDoesNotMatchOwner(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->forcedViewRoot = '/bob/files';

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('nicht nachweisbar', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testRefusesWhenTrashDisabledForOwner(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->trashFor['alice'] = false;

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('files_trashbin', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testRefusesFilesAboveTrashbinSize(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/gross.iso', 5000);
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/gross.iso.d1');
		$this->trashbinSize = '1000';

		[$status] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testTrashbinSizeCountsWholeTrashAndThisRun(): void {
		$deleter = $this->deleter();
		$this->trashbinSize = '1000';
		$this->sizes['1:files_trashbin'] = 100; // Größe wird im Lauf nicht nachgezogen
		$files = [];
		foreach ([1, 2, 3] as $id) {
			$files[$id] = $this->file($id, 1, "files/f$id.txt", 400);
			$this->node('alice', $files[$id], TestHomeMountProvider::class, "files_trashbin/files/f$id.txt.d1");
		}

		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('alice', 1), $files[1])[0]);
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('alice', 1), $files[2])[0], '100 + 400 + 400 < 1000');
		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $files[3]);

		$this->assertSame(LogEntry::STATUS_ERROR, $status, '100 + 800 + 400 > 1000: Expire würde räumen');
		$this->assertStringContainsString('Papierkorb würde sie wegen Quota/Größe sofort endgültig räumen', (string)$message);
		$this->assertArrayNotHasKey(3, $this->deleteCalled);
	}

	public function testQuotaNearlyFullRefusesWhatExpireWouldPurge(): void {
		$deleter = $this->deleter();
		$mb = 1024 * 1024;
		$this->quota['alice'] = '20 MB';
		$this->sizes['1:files'] = 17 * $mb;
		$small = $this->file(1, 1, 'files/klein.bin', 1 * $mb);
		$big = $this->file(2, 1, 'files/gross.bin', 4 * $mb);
		$this->node('alice', $small, TestHomeMountProvider::class, 'files_trashbin/files/klein.bin.d1');
		$this->node('alice', $big, TestHomeMountProvider::class, 'files_trashbin/files/gross.bin.d1');

		// klein: frei nach dem Verschieben 20 − 16 = 4 MB, davon 50 % = 2 MB > 1 MB im Papierkorb
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('alice', 1), $small)[0]);
		// groß: frei 20 − 12 = 8 MB, 50 % = 4 MB, Papierkorb danach 1 + 4 = 5 MB → Expire räumt
		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $big);
		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('Quota', (string)$message);
		$this->assertArrayNotHasKey(2, $this->deleteCalled);
	}

	/**
	 * Objektspeicher als Primärspeicher: kein HomeCache, die Konto-Wurzel zählt files/,
	 * Papierkorb und Versionen – und files_trashbin rechnet mit ihr. Zahlen aus dem Review
	 * (Quota 10 MB, 3 MB bleibend, 1,4 MB schon im Papierkorb, 2 MB fällig; Harness S23).
	 */
	public function testObjectStoreRootIncludesTrashForQuota(): void {
		$deleter = $this->deleter();
		$mb = 1024 * 1024;
		$this->storageIds[1] = 'object::user:alice';
		$this->quota['alice'] = '10 MB';
		$this->sizes['1:files'] = 5 * $mb;
		$this->sizes['1:files_trashbin'] = 1468006;
		$this->sizes['1:'] = 5 * $mb + 1468006;
		$f = $this->file(1, 1, 'files/target.bin', 2 * $mb);
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/target.bin.d1');

		// files/ allein: frei 10 − 3 = 7 MB, 50 % = 3,5 MB > 3,4 MB – Nextcloud aber: Wurzel
		// 6,4 MB, frei 3,6 MB, 50 % = 1,8 MB < 3,4 MB → Expire räumt beide Einträge
		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('Quota', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	/**
	 * Lokaler Speicher (HomeCache): es zählt files/ ohne die Datei. Die Wurzelzeile im Filecache
	 * ist dort veraltet (Harness S12: nach dem Entfernen der Skeleton-Dateien noch 62,5 MB) und
	 * darf nicht zählen. Unbekannte Speicherart rechnet vorsichtig mit der Wurzel.
	 */
	public function testLocalHomeIgnoresStaleRootRow(): void {
		$mb = 1024 * 1024;
		foreach ([['home::alice', LogEntry::STATUS_DELETED], ['local::/srv/data/alice/', LogEntry::STATUS_DELETED], [null, LogEntry::STATUS_ERROR]] as [$id, $expected]) {
			$this->storageIds = [1 => $id];
			$deleter = $this->deleter();
			$this->quota['alice'] = '10 MB';
			$this->sizes['1:files'] = 5 * $mb;
			$this->sizes['1:files_trashbin'] = 1468006;
			$this->sizes['1:'] = (int)(62.5 * $mb);
			$f = $this->file(1, 1, 'files/target.bin', 2 * $mb);
			$this->nodes = [];
			$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/target.bin.d1');

			$this->assertSame($expected, $deleter->delete($this->homeRoot('alice', 1), $f)[0], 'Speicher ' . var_export($id, true));
		}
	}

	public function testNoQuotaNoTrashbinSizeNoLimit(): void {
		$deleter = $this->deleter();
		$this->sizes['1:files'] = 10 ** 12;
		$this->sizes['1:files_trashbin'] = 10 ** 12;
		$f = $this->file(1, 1, 'files/a.txt', 10 ** 9);
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('alice', 1), $f)[0]);
	}

	public function testTrashbinSizeWithWrongTypeIsRefused(): void {
		$deleter = $this->deleter();
		$this->trashbinSizeConflict = true;
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('falschem Typ', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testExceptionInTrashHaltsAllFurtherDeletions(): void {
		$deleter = $this->deleter();
		$a = $this->file(1, 1, 'files/a.txt');
		$b = $this->file(2, 2, 'files/b.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->node('bob', $b, TestHomeMountProvider::class, 'files_trashbin/files/b.txt.d1');
		$this->deleteThrows = [1];

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $a);
		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('angehalten', (string)$message);
		$this->assertNotNull($deleter->haltReason());

		// Anderes Konto: nicht einmal versucht – TrashManager könnte noch pausiert sein
		[$status] = $deleter->delete($this->homeRoot('bob', 2), $b);
		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertArrayNotHasKey(2, $this->deleteCalled);

		// Nächster Lauf beginnt frisch
		$deleter->releaseContext();
		$this->assertNull($deleter->haltReason());
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('bob', 2), $b)[0]);
	}

	/**
	 * LegacyTrashBackend räumt deletedFiles nach einer Ausnahme nicht ab: Ein späterer Lauf im
	 * selben Prozess (background-job:worker) löschte denselben Pfad endgültig. Daher nie wieder
	 * in diesem Prozess – auch nicht nach releaseContext() und mit neuer Deleter-Instanz.
	 */
	public function testPathWithExceptionStaysBlockedForTheWholeProcess(): void {
		$deleter = $this->deleter();
		$a = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->deleteThrows = [1];
		$this->assertSame(LogEntry::STATUS_ERROR, $deleter->delete($this->homeRoot('alice', 1), $a)[0]);
		$deleter->releaseContext();
		$this->assertNull($deleter->haltReason());

		// Ursache „behoben“ – der Papierkorb hielte den Pfad trotzdem für schon verschoben
		$this->deleteThrows = [];
		$this->deleteCalled = [];
		foreach ([$deleter, $this->deleter()] as $d) {
			[$status, $message] = $d->delete($this->homeRoot('alice', 1), $a);
			$this->assertSame(LogEntry::STATUS_ERROR, $status);
			$this->assertStringContainsString('Prozessneustart', (string)$message);
			$this->assertArrayNotHasKey(1, $this->deleteCalled, 'nicht einmal versucht');
			$this->assertNull($d->haltReason(), 'andere Dateien laufen weiter');
		}
		// Neue Datei am selben Pfad (andere ID): ebenfalls nicht
		$a2 = $this->file(5, 1, 'files/a.txt');
		$this->assertSame(LogEntry::STATUS_ERROR, $deleter->delete($this->homeRoot('alice', 1), $a2)[0]);
		$this->assertArrayNotHasKey(5, $this->deleteCalled);
	}

	public function testLockOnTheFileItselfSkipsOnlyThisFile(): void {
		$deleter = $this->deleter();
		$a = $this->file(1, 1, 'files/a.txt');
		$b = $this->file(2, 1, 'files/b.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/b.txt.d1');
		// View::unlink holt die Sperre vor dem Papierkorb – ein lesender Sync-Client reicht
		$this->deleteLocked = [1 => '/alice/files/a.txt'];

		[$status] = $deleter->delete($this->homeRoot('alice', 1), $a);
		$this->assertSame(LogEntry::STATUS_SKIPPED_LOCKED, $status);
		$this->assertNull($deleter->haltReason(), 'Fehler nur für diese Datei');
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('alice', 1), $b)[0], 'nächste Datei läuft weiter');
	}

	public function testLockElsewhereFromDeleteStillHalts(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		// Sperre im Papierkorb: Zustand von TrashManager unklar
		$this->deleteLocked = [1 => 'files_trashbin/files/a.txt.d1'];

		[$status] = $deleter->delete($this->homeRoot('alice', 1), $f);
		$this->assertSame(LogEntry::STATUS_SKIPPED_LOCKED, $status);
		$this->assertNotNull($deleter->haltReason());
	}

	public function testLockOnParentFolderSkipsWithoutBlockingThePathForTheProcess(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/Projekt/a.txt');
		$b = $this->file(2, 1, 'files/b.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/b.txt.d1');
		// View::lockFile sperrt die Elternordner geteilt – vor dem Papierkorb; ein anderer Prozess
		// benennt „Projekt“ gerade um (exklusive Sperre)
		$this->deleteLocked = [1 => '/alice/files/Projekt'];

		[$status, $message] = $deleter->delete($root, $a);
		$this->assertSame(LogEntry::STATUS_SKIPPED_LOCKED, $status);
		$this->assertStringNotContainsString('angehalten', (string)$message);
		$this->assertNull($deleter->haltReason(), 'Papierkorb nicht berührt – Lauf läuft weiter');
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $b)[0]);
		$deleter->releaseContext();

		// Sperre weg: im selben Prozess (occ background-job:worker) wieder löschbar
		$this->deleteLocked = [];
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $a)[0], 'kein „Prozessneustart“-Fehler');
	}

	public function testLockOnAccountRootOrSiblingStillHalts(): void {
		// „/alice“ sperren auch View-Operationen im Papierkorb; „/alice/files/Pro“ ist kein Elternordner
		foreach (['/alice', '/alice/files/Pro'] as $i => $locked) {
			$deleter = $this->deleter();
			$f = $this->file(10 + $i, 1, "files/Projekt/a$i.txt");
			$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
			$this->deleteLocked = [$f->fileId => $locked];
			$this->assertSame(LogEntry::STATUS_SKIPPED_LOCKED, $deleter->delete($this->homeRoot('alice', 1), $f)[0]);
			$this->assertNotNull($deleter->haltReason(), $locked);
		}
	}

	public function testTeamMemberWithoutDeleteRightIsSkippedNotHalted(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob', 'carol']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->node('carol', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->deletable['bob'] = false; // Lese-Gruppe bzw. ACL ohne Löschen
		$this->groupTrash = true;

		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('carol')], $deleter->delete($root, $f));
		$this->assertSame(['bob', 'carol'], $this->setups);
		$this->assertSame(['resume', 'delete:1'], $this->calls, 'über bob gar nicht erst versucht');
		$this->assertNull($deleter->haltReason());
	}

	public function testNoMemberMayDeleteIsAnErrorWithoutHalt(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->deletable['bob'] = false;

		[$status, $message] = $deleter->delete($root, $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('Löschrecht', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
		$this->assertNull($deleter->haltReason());
	}

	public function testVersionsCountTowardsTrashQuota(): void {
		$deleter = $this->deleter();
		$mb = 1024 * 1024;
		// Beispiel aus dem Review, verkleinert: Quota 100, files/ 90, Datei 2 + Versionen 4.5
		$this->quota['alice'] = '100 MB';
		$this->sizes['1:files'] = 90 * $mb;
		$f = $this->file(1, 1, 'files/bericht.docx', 2 * $mb);
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/bericht.docx.d1');
		$this->versions[1] = (int)(4.5 * $mb);

		// frei nach dem Verschieben 100 − 88 = 12 MB, 50 % = 6 MB < 2 + 4,5 MB → Expire räumt
		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('Versionen', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);

		// Ohne files_versions wandern keine Versionen mit – dann passt die Datei
		$this->versionsApp = false;
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('alice', 1), $f)[0]);
	}

	public function testVersionsOfThisRunAddUpInTrash(): void {
		$deleter = $this->deleter();
		$this->trashbinSize = '1000';
		$a = $this->file(1, 1, 'files/a.txt', 100);
		$b = $this->file(2, 1, 'files/b.txt', 100);
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/b.txt.d1');
		$this->versions = [1 => 500, 2 => 350];

		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($this->homeRoot('alice', 1), $a)[0], '100 + 500 < 1000');
		[$status] = $deleter->delete($this->homeRoot('alice', 1), $b);
		$this->assertSame(LogEntry::STATUS_ERROR, $status, 'im Papierkorb 100 + 500 aus diesem Lauf, dazu 100 + 350 > 1000');
	}

	public function testUnknownVersionSizeIsRefused(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->versions[1] = null;

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('Versionen unbekannt', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testTrashIsResumedBeforeEveryDelete(): void {
		$deleter = $this->deleter();
		$a = $this->file(1, 1, 'files/a.txt');
		$b = $this->file(2, 1, 'files/b.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/b.txt.d1');
		$deleter->delete($this->homeRoot('alice', 1), $a);
		$deleter->delete($this->homeRoot('alice', 1), $b);
		$this->assertSame(['resume', 'delete:1', 'resume', 'delete:2'], $this->calls);
	}

	public function testMovedAfterRecheckIsSkipped(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		// Nach der Neuprüfung in „Nie löschen“ verschoben – gleiche ID, gleiche mtime
		$this->entryAfter[1] = ['storage' => 1, 'path' => 'files/Behalten/a.txt'];

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $status);
		$this->assertStringContainsString('verschoben', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testRefusesPartFiles(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/upload.PART');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/x');
		[$status] = $deleter->delete($this->homeRoot('alice', 1), $f);
		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testDetectsPermanentDeletion(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, null);

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $status);
		$this->assertStringContainsString('nicht im Papierkorb', (string)$message);
	}

	public function testNeverDeletesThroughShareMount(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		// Gleiche Datei-ID, einmal über eine Freigabe (CacheJail meldet den Quell-Storage), einmal im Home
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1', shared: true);
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');

		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($this->homeRoot('alice', 1), $f));
		$this->assertSame('files_trashbin/files/a.txt.d1', $this->entryAfter[1]['path']);
	}

	public function testOnlyShareMountFoundIsAnError(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1', shared: true);

		[$status] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testTeamFolderUsesMemberAndGroupTrash(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob', 'carol']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->trashFor['bob'] = false; // bob hat keinen Papierkorb (Gruppenfreigabe der App) → carol
		$this->node('carol', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->groupTrash = true;

		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('carol')], $deleter->delete($root, $f));
		$this->assertSame(['carol'], $this->setups);
	}

	public function testTeamFolderDeletesViaFirstSortedMemberAndNamesItInLog(): void {
		// Papierkorb/Aktivität nennen das Konto als Löschenden – die Wahl darf nicht davon abhängen,
		// wessen Dateisystem gerade eingerichtet ist (hier: carol nach ihrer eigenen Datei)
		$deleter = $this->deleter();
		$own = $this->file(2, 3, 'files/c.txt');
		$this->node('carol', $own, TestHomeMountProvider::class, 'files_trashbin/files/c.txt.d1');
		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('carol')], $deleter->delete($this->homeRoot('carol', 3), $own));

		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['carol', 'bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->node('carol', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->groupTrash = true;

		[$status, $message] = $deleter->delete($root, $f);

		$this->assertSame(LogEntry::STATUS_DELETED, $status);
		$this->assertSame(['carol', 'bob'], $this->setups, 'Team-Ordner über bob (sortiert zuerst), nicht über den eingerichteten Kontext carol');
		$this->assertSame('bob', $this->activeUser?->getUID());
		$this->assertStringContainsString('über Konto bob', (string)$message);
		$this->assertStringContainsString('folder_retention', (string)$message);
	}

	public function testTeamFolderExceptionTriesNoOtherMember(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob', 'carol']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->node('carol', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->deleteThrows = [1];

		[$status] = $deleter->delete($root, $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertSame(['bob'], $this->setups, 'nach der Ausnahme kein weiterer Kandidat');
		$this->assertSame(['resume', 'delete:1', 'resume'], $this->calls, 'nach der Ausnahme Papierkorb wieder freigeben (Folgejobs im selben Prozess)');
	}

	public function testTeamFolderQuotaExceededIsRefused(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt', 100);
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->groupQuota = 1000;
		$this->sizes['7:__groupfolders/3'] = 700;
		$this->sizes['7:__groupfolders/trash/3'] = 400;

		[$status, $message] = $deleter->delete($root, $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('Team-Ordner-Quota', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testTeamFolderWithinQuotaAndSeparateStorageLayout(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 8, 80, 'files', 'Team', ['bob']);
		$f = $this->file(1, 8, 'files/a.txt', 100);
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, 'trash/a.txt.d1');
		$this->groupTrash = true;
		$this->groupQuota = 1000;
		$this->sizes['8:files'] = 500;
		$this->sizes['8:trash'] = 400;

		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $f)[0]);
	}

	public function testTeamFolderUnknownQuotaIsRefused(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/a.txt.d1');
		$this->groupQuota = null;
		[$status] = $deleter->delete($root, $f);
		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testTeamFolderGoneIsPermanentDeletion(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, null);

		[$status] = $deleter->delete($root, $f);
		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $status);
	}

	/**
	 * groupfolders mit Verschlüsselung kopiert in den Papierkorb: alte ID weg, Eintrag in
	 * oc_group_folders_trash nennt sie weiter, die Datei steht unter neuer ID am Papierkorb-Ort.
	 */
	private function encryptedTeamDelete(int $newId, bool $foreign = false): array {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, null);
		$this->groupTrashRows[1] = ['name' => 'a.txt', 'time' => 100];
		$this->trashSlots['7:a.txt.d100'] = $newId;
		$this->entryAfter[$newId] = ['storage' => 7, 'path' => '__groupfolders/trash/3/a.txt.d100'];
		$this->groupTrashIds[$newId] = $foreign;
		$result = $deleter->delete($root, $f);
		$deleter->verifyRecentTrash();
		return [$result, $deleter->takeLost()];
	}

	public function testTeamFolderEncryptedCopyWithNewIdCountsAsTrash(): void {
		[[$status], $lost] = $this->encryptedTeamDelete(99);
		$this->assertSame(LogEntry::STATUS_DELETED, $status);
		$this->assertSame([], $lost);
		$this->assertContains('7:__groupfolders/trash/3/a.txt.d100', $this->trashLookups);
	}

	public function testTeamFolderTrashSlotOfOtherEntryIsPermanentDeletion(): void {
		// Unter Name+Zeit liegt eine Datei, die einem anderen Papierkorb-Eintrag gehört
		[[$status]] = $this->encryptedTeamDelete(98, true);
		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $status);
	}

	public function testTeamFolderTrashRowWithoutFileIsPermanentDeletion(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, RootProvider::GROUPFOLDER_PROVIDER, null);
		$this->groupTrashRows[1] = ['name' => 'a.txt', 'time' => 100];

		[$status] = $deleter->delete($root, $f);
		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $status);
	}

	public function testAccountTrashIgnoresGroupTrashRows(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 5, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, null);
		$this->groupTrashRows[1] = ['name' => 'a.txt', 'time' => 100];
		$this->trashSlots['5:a.txt.d100'] = 99;
		$this->entryAfter[99] = ['storage' => 5, 'path' => 'files_trashbin/files/a.txt.d100'];
		$this->groupTrashIds[99] = false;

		[$status] = $deleter->delete($this->homeRoot('alice', 5), $f);
		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $status);
	}

	public function testTeamFolderIgnoresHomeMountOfSameFile(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$f = $this->file(1, 7, '__groupfolders/3/a.txt');
		$this->node('bob', $f, TestHomeMountProvider::class, 'irgendwo');

		[$status] = $deleter->delete($root, $f);
		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	public function testReleaseContextRestoresPreviousUser(): void {
		$deleter = $this->deleter();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$deleter->delete($this->homeRoot('alice', 1), $f);

		$deleter->releaseContext();

		$this->assertNull($this->activeUser);
		$this->assertSame(2, $this->teardowns);
	}

	public function testReleaseWithoutSwitchDoesNothing(): void {
		$deleter = $this->deleter();
		$deleter->releaseContext();
		$this->assertSame(0, $this->teardowns);
	}

	// --- Papierkorb nach dem Lauf nie pausiert zurücklassen (NC 34, cron.php: Folgejobs) ---

	public function testReleaseContextAlwaysResumesTrash(): void {
		$deleter = $this->deleter();
		$deleter->releaseContext();
		$this->assertSame(1, $this->resumes, 'auch ohne Kontowechsel');

		$this->resumes = 0;
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');
		$this->deleteThrows = [1];
		$deleter->delete($this->homeRoot('alice', 1), $f);
		$this->calls = [];
		$deleter->releaseContext();
		$this->assertSame(['resume'], $this->calls, 'nach Ausnahme im Papierkorb-Backend');
	}

	// --- Web-Cron / X-NC-Skip-Trashbin ---------------------------------------------------

	public function testRefusesOutsideCli(): void {
		$deleter = $this->deleter();
		$this->cli = false;
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('System-Cron', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
		$this->assertSame([], $this->setups, 'nicht einmal den Kontext umgestellt');
	}

	public function testRefusesWhenRequestSkipsTrashbin(): void {
		$deleter = $this->deleter();
		$this->skipHeader = true;
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/a.txt.d1');

		[$status, $message] = $deleter->delete($this->homeRoot('alice', 1), $f);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('X-NC-Skip-Trashbin', (string)$message);
		$this->assertArrayNotHasKey(1, $this->deleteCalled);
	}

	// --- gleicher Name in derselben Sekunde: files_trashbin überschreibt <name>.d<time()> ---

	public function testSameNameWaitsForNextSecondSoNothingIsOverwritten(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$files = [];
		foreach (['dA', 'dB', 'dC'] as $i => $dir) {
			$files[] = $f = $this->file($i + 1, 1, "files/$dir/Bericht.txt");
			$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');
		}

		foreach ($files as $f) {
			$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($root, $f));
		}

		foreach ($files as $f) {
			$this->assertSame('files_trashbin/files/Bericht.txt.d1', $this->entryAfter[$f->fileId]['path'] ?? null, 'Datei ' . $f->fileId . ' liegt noch im Papierkorb');
		}
		$this->assertSame(2, $this->pauses, 'je gleichnamiger Datei bis zur nächsten Sekunde gewartet');
		$this->assertSame([], $deleter->takeLost());
		$this->assertNull($deleter->haltReason());
	}

	public function testSameNameIsCaseInsensitiveAndDifferentNamesDoNotWait(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/x/scan.pdf');
		$b = $this->file(2, 1, 'files/y/andere.pdf');
		$c = $this->file(3, 1, 'files/z/SCAN.pdf');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/scan.pdf.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/andere.pdf.d1');
		$this->node('alice', $c, TestHomeMountProvider::class, 'files_trashbin/files/SCAN.pdf.d1');

		$deleter->delete($root, $a);
		$deleter->delete($root, $b);
		$this->assertSame(0, $this->pauses, 'anderer Name: kein Warten');
		$deleter->delete($root, $c);
		$this->assertSame(1, $this->pauses, 'Groß-/Kleinschreibung: vorsichtshalber gleich behandelt');
	}

	public function testSameNameInOtherTrashDoesNotWait(): void {
		$deleter = $this->deleter();
		$a = $this->file(1, 1, 'files/Bericht.txt');
		$b = $this->file(2, 2, 'files/Bericht.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');
		$this->node('bob', $b, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');
		$deleter->delete($this->homeRoot('alice', 1), $a);
		$deleter->delete($this->homeRoot('bob', 2), $b);
		$this->assertSame(0, $this->pauses);
	}

	public function testVanishedEarlierTrashEntryIsReportedAndHalts(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/dA/Bericht.txt');
		$b = $this->file(2, 1, 'files/dB/Bericht.txt');
		$c = $this->file(3, 1, 'files/c.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');
		$this->node('alice', $c, TestHomeMountProvider::class, 'files_trashbin/files/c.txt.d1');

		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $a)[0]);
		// Eintrag von a geht beim Verschieben von b verloren (z. B. Uhr springt zurück)
		$this->entryAfter[1] = null;
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $b)[0], 'b selbst liegt im Papierkorb');

		$lost = $deleter->takeLost();
		$this->assertCount(1, $lost);
		$this->assertSame(1, $lost[0][1]->fileId);
		$this->assertStringContainsString('überschrieben', $lost[0][2]);
		$this->assertSame([], $deleter->takeLost(), 'nur einmal gemeldet');
		$this->assertNotNull($deleter->haltReason());
		[$status] = $deleter->delete($root, $c);
		$this->assertSame(LogEntry::STATUS_ERROR, $status, 'Lauf angehalten');
		$this->assertArrayNotHasKey(3, $this->deleteCalled);
	}

	public function testRestoredEarlierEntryIsNotLost(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/dA/Bericht.txt');
		$b = $this->file(2, 1, 'files/dB/Bericht.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');

		$deleter->delete($root, $a);
		$this->entryAfter[1] = ['storage' => 1, 'path' => 'files/dA/Bericht.txt']; // vom Nutzer zurückgeholt
		$deleter->delete($root, $b);

		$this->assertSame([], $deleter->takeLost());
		$this->assertNull($deleter->haltReason());
	}

	public function testTeamFolderSameNameWaitsToo(): void {
		$deleter = $this->deleter();
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$this->groupTrash = true;
		$a = $this->file(1, 7, '__groupfolders/3/x/scan.pdf');
		$b = $this->file(2, 7, '__groupfolders/3/y/scan.pdf');
		$this->node('bob', $a, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/scan.pdf.d1');
		$this->node('bob', $b, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/scan.pdf.d1');

		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $a)[0]);
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $b)[0]);
		$this->assertSame(1, $this->pauses);
		$this->assertNotNull($this->entryAfter[1], 'erster Eintrag nicht überschrieben');
	}

	public function testSameNameFromPreviousRunInSameSecondWaitsToo(): void {
		// occ-Lauf 1 verschiebt A/Protokoll.pdf und gibt die Sperre frei; Lauf 2 (Speicher leer)
		// will B/Protokoll.pdf noch in derselben Sekunde verschieben
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/A/Protokoll.pdf');
		$b = $this->file(2, 1, 'files/B/Protokoll.pdf');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/Protokoll.pdf.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/Protokoll.pdf.d1');

		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($root, $a));
		$deleter->releaseContext(); // Laufende: Speicher des Laufs geleert
		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($root, $b));

		$this->assertSame(1, $this->pauses, 'bis zur nächsten Sekunde gewartet');
		$this->assertContains('1:files_trashbin/files/Protokoll.pdf.d100', $this->trashLookups, 'Name der Sekunde im Filecache nachgesehen');
		$this->assertSame('files_trashbin/files/Protokoll.pdf.d1', $this->entryAfter[1]['path'] ?? null, 'Eintrag aus Lauf 1 nicht überschrieben');
		$this->assertSame([], $deleter->takeLost());
		$this->assertNull($deleter->haltReason());
	}

	public function testTeamFolderSameNameFromPreviousRunWaitsToo(): void {
		$root = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 70, '__groupfolders/3', 'Team', ['bob']);
		$this->groupTrash = true;
		$a = $this->file(1, 7, '__groupfolders/3/x/scan.pdf');
		$b = $this->file(2, 7, '__groupfolders/3/y/scan.pdf');
		$this->node('bob', $a, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/scan.pdf.d1');
		$this->node('bob', $b, RootProvider::GROUPFOLDER_PROVIDER, '__groupfolders/trash/3/scan.pdf.d1');

		$this->assertSame(LogEntry::STATUS_DELETED, $this->deleter()->delete($root, $a)[0]);
		// neuer Prozess (Hintergrundjob übernimmt die eben freigegebene Sperre)
		$this->assertSame(LogEntry::STATUS_DELETED, $this->deleter()->delete($root, $b)[0]);

		$this->assertSame(1, $this->pauses);
		$this->assertContains('7:__groupfolders/trash/3/scan.pdf.d100', $this->trashLookups);
		$this->assertNotNull($this->entryAfter[1], 'erster Eintrag nicht überschrieben');
	}

	public function testLongNameFromPreviousRunInSameSecondWaitsToo(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/' . str_repeat('z', 225) . '-1.txt');
		$b = $this->file(2, 1, 'files/' . str_repeat('z', 225) . '-2.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/b.d1');

		$deleter->delete($root, $a);
		$deleter->releaseContext();
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $b)[0]);

		$this->assertSame(1, $this->pauses, 'langer Name: jeder Eintrag derselben Sekunde zählt');
	}

	public function testTrashNameTakenForTooLongIsRefused(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$b = $this->file(2, 1, 'files/B/Protokoll.pdf');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/Protokoll.pdf.d1');
		// Eintrag „Protokoll.pdf.d<jede Sekunde>“ – z. B. Uhr steht
		$this->entryAfter[9] = ['storage' => 1, 'path' => 'files_trashbin/files/Protokoll.pdf.d100'];
		$this->onPause = function () {
			$this->trashSlots['1:Protokoll.pdf.d' . $this->clock] = 9;
		};
		$this->trashSlots['1:Protokoll.pdf.d100'] = 9;

		[$status, $message] = $deleter->delete($root, $b);

		$this->assertSame(LogEntry::STATUS_ERROR, $status);
		$this->assertStringContainsString('belegt', (string)$message);
		$this->assertArrayNotHasKey(2, $this->deleteCalled);
		$this->assertNull($deleter->haltReason(), 'nichts versucht – kein Grund, den Lauf anzuhalten');
	}

	public function testEarlierEntryEmptiedByUserLaterInRunIsNotReportedAsOverwritten(): void {
		// bob/X/desktop.ini verschoben, bob leert danach seinen Papierkorb; später im selben Lauf
		// kommt bob/Y/desktop.ini dran – kein „von Nextcloud überschrieben“, keine Sperre
		$deleter = $this->deleter();
		$root = $this->homeRoot('bob', 2);
		$x = $this->file(1, 2, 'files/X/desktop.ini');
		$y = $this->file(2, 2, 'files/Y/desktop.ini');
		$this->node('bob', $x, TestHomeMountProvider::class, 'files_trashbin/files/desktop.ini.d1');
		$this->node('bob', $y, TestHomeMountProvider::class, 'files_trashbin/files/desktop.ini.d1');

		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $x)[0]);
		$this->clock += 40; // Lauf arbeitet weiter
		$this->entryAfter[1] = null; // Papierkorb geleert bzw. Eintrag abgelaufen
		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('bob')], $deleter->delete($root, $y));

		$this->assertSame([], $deleter->takeLost(), 'kein Verlust durch die App');
		$this->assertNull($deleter->haltReason(), 'Lauf nicht angehalten');
		$this->assertSame(0, $this->pauses);
	}

	public function testUserOverwritingInSameSecondIsFoundAtRunEnd(): void {
		// App verschiebt a um 100; noch in Sekunde 100 löscht der Nutzer gleichnamig – Nextcloud
		// überschreibt den Eintrag der App. Die App löscht diesen Namen danach nicht mehr.
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/dA/Bericht.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');

		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $a)[0]);
		$this->entryAfter[1] = null; // vom Nutzer überschrieben
		$this->assertSame([], $deleter->takeLost(), 'während des Laufs nicht erkennbar');

		$deleter->verifyRecentTrash();
		$this->assertSame(2, $this->pauses, 'eine volle Sekunde nach der letzten Löschung gewartet');
		$this->assertSame(102, $this->clock);
		$lost = $deleter->takeLost();
		$this->assertCount(1, $lost);
		$this->assertSame(1, $lost[0][1]->fileId);
		$this->assertStringContainsString('endgültig gelöscht', $lost[0][2]);
	}

	public function testRunEndChecksOnlyLastTwoSecondsAndReportsOnce(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$old = $this->file(1, 1, 'files/alt.txt');
		$mid = $this->file(2, 1, 'files/mitte.txt');
		$new = $this->file(3, 1, 'files/neu.txt');
		$kept = $this->file(4, 1, 'files/bleibt.txt');
		foreach ([$old, $mid, $new, $kept] as $f) {
			$this->node('alice', $f, TestHomeMountProvider::class, 'files_trashbin/files/' . basename($f->path) . '.d1');
		}
		$deleter->delete($root, $old);
		$this->clock += 10;
		$deleter->delete($root, $mid);
		$this->clock++;
		$deleter->delete($root, $new);
		$deleter->delete($root, $kept);
		$this->entryAfter[1] = null; // vor 11 s: kann ebenso der Nutzer geleert haben
		$this->entryAfter[2] = null;
		$this->entryAfter[3] = null;

		$deleter->verifyRecentTrash();
		$ids = array_map(fn (array $l) => $l[1]->fileId, $deleter->takeLost());
		sort($ids);
		$this->assertSame([2, 3], $ids);
		$deleter->verifyRecentTrash();
		$this->assertSame([], $deleter->takeLost(), 'nur einmal gemeldet');
	}

	public function testUserOverwritingMidRunIsFoundAtRunEndEvenAfterLaterDeletions(): void {
		// App verschiebt a um 100; noch in Sekunde 100 löscht der Nutzer gleichnamig – Nextcloud
		// überschreibt den Eintrag der App. Danach löscht der Lauf noch über Sekunden andere Namen.
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/A/bericht.pdf');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/bericht.pdf.d1');
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $a)[0]);
		// Nutzer löscht /B/bericht.pdf in derselben Sekunde: sein Eintrag ersetzt den der App
		$this->trashSlots['1:bericht.pdf.d100'] = 99;
		$this->entryAfter[99] = ['storage' => 1, 'path' => 'files_trashbin/files/bericht.pdf.d100'];
		$this->entryAfter[1] = null;
		for ($i = 2; $i <= 6; $i++) {
			$this->clock++;
			$f = $this->file($i, 1, "files/anders$i.txt");
			$this->node('alice', $f, TestHomeMountProvider::class, "files_trashbin/files/anders$i.txt.d1");
			$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $f)[0]);
		}
		$this->assertSame(105, $this->clock, 'Löschung von a liegt weit vor den letzten zwei Sekunden');

		$deleter->verifyRecentTrash();
		$lost = $deleter->takeLost();
		$this->assertCount(1, $lost, 'nur a, die übrigen liegen im Papierkorb');
		$this->assertSame(1, $lost[0][1]->fileId);
		$this->assertStringContainsString('überschrieben', $lost[0][2]);
		$this->assertStringContainsString('endgültig gelöscht', $lost[0][2]);
		$this->assertNotNull($deleter->haltReason());
	}

	public function testOlderEntryGoneWithoutReplacementIsNotReportedAtRunEnd(): void {
		// Eintrag vom Anfang des Laufs fehlt, unter seinem Namen liegt nichts: Nutzer hat ihn
		// endgültig gelöscht bzw. den Papierkorb geleert – kein Verlust durch die App
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/A/bericht.pdf');
		$b = $this->file(2, 1, 'files/anders.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/bericht.pdf.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/anders.txt.d1');
		$deleter->delete($root, $a);
		$this->clock += 30;
		$deleter->delete($root, $b);
		$this->entryAfter[1] = null;

		$deleter->verifyRecentTrash();
		$this->assertSame([], $deleter->takeLost());
		$this->assertNull($deleter->haltReason());
	}

	public function testRunEndWithoutVerifiedDeletionDoesNotWait(): void {
		$deleter = $this->deleter();
		$deleter->verifyRecentTrash();
		$f = $this->file(1, 1, 'files/a.txt');
		$this->node('alice', $f, TestHomeMountProvider::class, null); // endgültig weg – schon als deleted_final verbucht
		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $deleter->delete($this->homeRoot('alice', 1), $f)[0]);
		$deleter->verifyRecentTrash();
		$this->assertSame(0, $this->pauses);
		$this->assertSame([], $deleter->takeLost(), 'nicht doppelt gemeldet');
	}

	public function testEarlierEntryFromPreviousSecondIsStillRechecked(): void {
		// eine Sekunde Spiel: Eintrag aus der Sekunde vor dem Löschversuch wird weiter nachgeprüft
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/dA/Bericht.txt');
		$b = $this->file(2, 1, 'files/dB/Bericht.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/Bericht.txt.d1');

		$deleter->delete($root, $a);
		$this->clock++; // nächste Sekunde
		$this->entryAfter[1] = null;
		$deleter->delete($root, $b);

		$this->assertCount(1, $deleter->takeLost());
		$this->assertNotNull($deleter->haltReason());
	}

	/** Papierkorb-Name wie Trashbin::getTrashFilename (NC 34/35), ohne „.d<Zeit>“ */
	private static function ncTrashName(string $name): string {
		$t = $name . '.d1791111730';
		if (strlen($t) > 250) {
			$remove = strlen($t) - 250 + 1;
			$half = intdiv(mb_strlen($t), 2);
			$t = mb_substr($t, 0, $half - $remove) . '_' . mb_substr($t, $half);
		}
		return substr($t, 0, -12);
	}

	/** @return list<array{0: string, 1: string}> */
	public static function longNamesTruncatedAlike(): array {
		return [
			'Kürzungszone (Review A)' => [str_repeat('x', 120) . 'C123' . str_repeat('y', 118) . '.pdf', str_repeat('x', 120) . 'D456' . str_repeat('y', 118) . '.pdf'],
			'245 Zeichen (Review B)' => [str_repeat('a', 122) . '1' . str_repeat('b', 118) . '.txt', str_repeat('a', 122) . '2' . str_repeat('b', 118) . '.txt'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('longNamesTruncatedAlike')]
	public function testLongNamesTruncatedAlikeByTrashbinWaitToo(string $nameA, string $nameB): void {
		$this->assertNotSame($nameA, $nameB);
		$this->assertSame(self::ncTrashName($nameA), self::ncTrashName($nameB), 'Vorbedingung: Nextcloud kürzt beide auf denselben Papierkorb-Namen');
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, "files/$nameA");
		$b = $this->file(2, 1, "files/$nameB");
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/' . self::ncTrashName($nameA) . '.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/' . self::ncTrashName($nameB) . '.d1');

		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($root, $a));
		$this->assertSame([LogEntry::STATUS_DELETED, $deleter->deletedVia('alice')], $deleter->delete($root, $b));

		$this->assertSame(1, $this->pauses, 'bis zur nächsten Sekunde gewartet');
		$this->assertNotNull($this->entryAfter[1], 'erster Eintrag nicht überschrieben');
		$this->assertSame([], $deleter->takeLost());
		$this->assertNull($deleter->haltReason());
	}

	public function testLongNamesAlwaysWaitForEachOtherAndAreRechecked(): void {
		// Unterschied am Ende – files_trashbin kürzt das nicht weg, Versionen („.v<n>.d<Zeit>“) evtl. doch
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/' . str_repeat('z', 225) . '-1.txt');
		$b = $this->file(2, 1, 'files/' . str_repeat('z', 225) . '-2.txt');
		$c = $this->file(3, 1, 'files/kurz.txt');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/a.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/b.d1');
		$this->node('alice', $c, TestHomeMountProvider::class, 'files_trashbin/files/kurz.txt.d1');

		$deleter->delete($root, $a);
		$deleter->delete($root, $c);
		$this->assertSame(0, $this->pauses, 'kurzer Name wartet nicht auf lange');
		$this->entryAfter[1] = null; // Eintrag von a geht beim Verschieben von b verloren
		$this->assertSame(LogEntry::STATUS_DELETED, $deleter->delete($root, $b)[0]);

		$this->assertSame(1, $this->pauses);
		$lost = $deleter->takeLost();
		$this->assertCount(1, $lost, 'lange Einträge werden untereinander nachgeprüft');
		$this->assertSame(1, $lost[0][1]->fileId);
		$this->assertNotNull($deleter->haltReason());
	}

	public function testFileMovedWhileWaitingForNextSecondIsSkipped(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/x/scan.pdf');
		$b = $this->file(2, 1, 'files/y/scan.pdf');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/scan.pdf.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/scan.pdf.d1');
		$deleter->delete($root, $a);
		// Während des Wartens verschiebt jemand b nach „Behalten“ (gleiche ID, gleiche mtime)
		$this->onPause = function () {
			$this->entryAfter[2] = ['storage' => 1, 'path' => 'files/Behalten/scan.pdf'];
		};

		[$status] = $deleter->delete($root, $b);

		$this->assertSame(1, $this->pauses);
		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $status);
		$this->assertArrayNotHasKey(2, $this->deleteCalled);
	}

	public function testFileChangedWhileWaitingForNextSecondIsSkipped(): void {
		$deleter = $this->deleter();
		$root = $this->homeRoot('alice', 1);
		$a = $this->file(1, 1, 'files/x/scan.pdf');
		$b = $this->file(2, 1, 'files/y/scan.pdf');
		$this->node('alice', $a, TestHomeMountProvider::class, 'files_trashbin/files/scan.pdf.d1');
		$this->node('alice', $b, TestHomeMountProvider::class, 'files_trashbin/files/scan.pdf.d1');
		$deleter->delete($root, $a);
		// Während des Wartens lädt ein Sync-Client eine neue Fassung hoch (gleiche ID, neue mtime)
		$this->onPause = function () {
			$this->mtimeNow[2] = 999;
		};

		[$status, $message] = $deleter->delete($root, $b);

		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $status);
		$this->assertStringContainsString('geändert', (string)$message);
		$this->assertArrayNotHasKey(2, $this->deleteCalled);
	}
}
