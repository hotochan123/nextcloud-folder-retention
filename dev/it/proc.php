<?php
// Second Nextcloud process for the integration harness (runs INSIDE the throwaway container, as www-data).
// Simulates a long-running process (cron.php) or a parallel run – with its own
// process cache of the app config, as in production.
//   php proc.php block <key> <timestamp>                set a block (like a run after a permanent deletion)
//   php proc.php wait <check key> <own key>
//       reads the block list, signals /tmp/s20-ready, waits for /tmp/s20-go, then checks again and
//       sets its own block (timestamp 300). Output: "before=<0|1> after=<0|1>"
//   php proc.php samesecond <fileid A> <fileid B>
//       two runs back to back (like "occ …run; occ …run" or the job taking over the block):
//       move A, end of run (releaseContext), immediately move B – ideally within the same
//       second. Output: "a=<status> b=<status> aEnd=<s> bStart=<s> bEnd=<s>"
//   php proc.php trashthrow <fileid>
//       two runs in the same process (like occ background-job:worker): in the first, the
//       locking backend throws an exception when locking the trash bin target (Trashbin::move2trash),
//       in the second it no longer does – same file again. Output: "a=<status> b=<status> | <message b>"
//   php proc.php repeat <fileid>
//       LogMapper::findRepeat/touch against the real database: an entry without a message,
//       the same report again, a different one. Output: "same=<id|-> other=<id|-> moved=<0|1>"
//   php proc.php migrate <version>
//       runs one migration step of the app via MigrationService::executeStep, the way prod gets a
//       schema change without a version bump (occ migrations:execute exists only with debug=true).
//       Output: "done" or "already"
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('folder_retention');
$settings = \OCP\Server::get(\OCA\FolderRetention\Service\Settings::class);

switch ($argv[1] ?? '') {
	case 'block':
		$settings->blockRoot($argv[2], 'Harness', 'proc.php', (int)$argv[3]);
		echo "ok\n";
		break;
	case 'wait':
		$before = $settings->isRootBlocked($argv[2]) ? 1 : 0;
		$settings->getCursor(); // app config is now in the process cache
		file_put_contents('/tmp/s20-ready', '1');
		for ($i = 0; $i < 300 && !file_exists('/tmp/s20-go'); $i++) {
			usleep(100_000);
		}
		$after = $settings->isRootBlocked($argv[2]) ? 1 : 0;
		$settings->blockRoot($argv[3], 'Harness P', 'proc.php', 300);
		echo "before=$before after=$after\n";
		break;
	case 'samesecond':
		\OC_App::loadApps(); // files_trashbin, groupfolders – like occ
		$fileCache = \OCP\Server::get(\OCA\FolderRetention\Service\FileCacheReader::class);
		$rootProvider = \OCP\Server::get(\OCA\FolderRetention\Service\RootProvider::class);
		$deleter = \OCP\Server::get(\OCA\FolderRetention\Service\Deleter::class);
		$rows = [];
		foreach ([(int)$argv[2], (int)$argv[3]] as $id) {
			$row = $fileCache->getFileRow($id);
			$located = $row === null ? null : $rootProvider->locate($row->parentId);
			if ($located === null) {
				fwrite(STDERR, "Datei $id nicht gefunden bzw. in keinem Bereich\n");
				exit(1);
			}
			$rows[] = [$located[0], $row];
		}
		// start at the beginning of a second so B would fall into the same second without waiting
		while (fmod(microtime(true), 1.0) > 0.05) {
			usleep(10_000);
		}
		[$a] = $deleter->delete(...$rows[0]);
		$aEnd = time();
		$deleter->releaseContext();
		$bStart = time();
		[$b] = $deleter->delete(...$rows[1]);
		$bEnd = time();
		$deleter->releaseContext();
		echo "a=$a b=$b aEnd=$aEnd bStart=$bStart bEnd=$bEnd\n";
		break;
	case 'trashthrow':
		\OC_App::loadApps();
		$inner = \OCP\Server::get(\OCP\Lock\ILockingProvider::class);
		$locking = new class($inner) implements \OCP\Lock\ILockingProvider {
			public bool $armed = true;
			public function __construct(private \OCP\Lock\ILockingProvider $inner) {
			}
			public function isLocked(string $path, int $type): bool {
				return $this->inner->isLocked($path, $type);
			}
			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				if ($this->armed) {
					foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
						if (($frame['function'] ?? '') === 'move2trash') {
							throw new \RuntimeException('Harness: Locking-Backend nicht erreichbar');
						}
					}
				}
				$this->inner->acquireLock($path, $type, $readablePath);
			}
			public function releaseLock(string $path, int $type): void {
				$this->inner->releaseLock($path, $type);
			}
			public function changeLock(string $path, int $targetType): void {
				$this->inner->changeLock($path, $targetType);
			}
			public function releaseAll(): void {
				$this->inner->releaseAll();
			}
		};
		// Trashbin::move2trash fetches the provider via Server::get on every call
		\OC::$server->registerService(\OCP\Lock\ILockingProvider::class, fn () => $locking);
		$fileCache = \OCP\Server::get(\OCA\FolderRetention\Service\FileCacheReader::class);
		$rootProvider = \OCP\Server::get(\OCA\FolderRetention\Service\RootProvider::class);
		$deleter = \OCP\Server::get(\OCA\FolderRetention\Service\Deleter::class);
		$out = [];
		foreach ([true, false] as $armed) {
			$locking->armed = $armed;
			$row = $fileCache->getFileRow((int)$argv[2]);
			$located = $row === null ? null : $rootProvider->locate($row->parentId);
			if ($located === null) {
				$out[] = ['fehlt', 'Datei nicht im Bereich bzw. weg'];
				continue;
			}
			$out[] = $deleter->delete($located[0], $row);
			$deleter->releaseContext();
		}
		echo 'a=' . $out[0][0] . ' b=' . $out[1][0] . ' | ' . str_replace("\n", ' ', (string)$out[1][1]) . "\n";
		break;
	case 'repeat':
		$mapper = \OCP\Server::get(\OCA\FolderRetention\Db\LogMapper::class);
		$e = new \OCA\FolderRetention\Db\LogEntry();
		$e->setFileId((int)$argv[2]);
		$e->setStorageId(1);
		$e->setPath('s35-repeat.txt');
		$e->setRuleLabel('s35');
		$e->setReferenceDate(1);
		$e->setReferenceSource('upload');
		$e->setDeletedAt(100);
		$e->setMode('real');
		$e->setStatus('error');
		$e->setMessage(null);
		$mapper->insert($e);
		$same = $mapper->findRepeat((int)$argv[2], 'real', 'error', 's35', null);
		$other = $mapper->findRepeat((int)$argv[2], 'real', 'error', 's35', 'andere Meldung');
		if ($same !== null) {
			$mapper->touch($same, 200);
		}
		$moved = $same !== null && $mapper->findPage(1, 0, ['search' => 's35-repeat'])[0]->getDeletedAt() === 200;
		echo 'same=' . ($same === $e->getId() ? 'id' : '-') . ' other=' . ($other ?? '-') . ' moved=' . ($moved ? 1 : 0) . "\n";
		break;
	case 'migrate':
		$ms = new \OC\DB\MigrationService('folder_retention', \OCP\Server::get(\OC\DB\Connection::class));
		if (in_array($argv[2], $ms->getMigratedVersions(), true)) {
			echo "already\n";
			break;
		}
		$ms->executeStep($argv[2]);
		echo "done\n";
		break;
	case 'purge':
		echo json_encode(\OCP\Server::get(\OCA\FolderRetention\Service\LogRetention::class)->purge()) . "\n";
		break;
	default:
		fwrite(STDERR, "unbekannter Modus\n");
		exit(2);
}
