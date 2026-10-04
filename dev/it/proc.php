<?php
// Zweiter Nextcloud-Prozess für den Integrations-Harness (läuft IM Wegwerf-Container, als www-data).
// Spielt einen lange laufenden Prozess (cron.php) bzw. einen parallelen Lauf nach – mit eigenem
// Prozess-Cache der App-Config, wie im Betrieb.
//   php proc.php block <schlüssel> <zeitpunkt>          Sperre setzen (wie ein Lauf nach endgültiger Löschung)
//   php proc.php wait <prüfschlüssel> <eigener schlüssel>
//       liest die Sperrliste, meldet /tmp/s20-ready, wartet auf /tmp/s20-go, prüft dann erneut und
//       setzt eine eigene Sperre (Zeitpunkt 300). Ausgabe: „before=<0|1> after=<0|1>“
//   php proc.php samesecond <fileid A> <fileid B>
//       zwei Läufe direkt hintereinander (wie „occ …run; occ …run“ bzw. Job übernimmt die Sperre):
//       A verschieben, Laufende (releaseContext), sofort B verschieben – möglichst in derselben
//       Sekunde. Ausgabe: „a=<status> b=<status> aEnd=<s> bStart=<s> bEnd=<s>“
//   php proc.php trashthrow <fileid>
//       zwei Läufe im selben Prozess (wie occ background-job:worker): Im ersten wirft das
//       Locking-Backend beim Sperren des Papierkorb-Ziels (Trashbin::move2trash) eine Ausnahme,
//       im zweiten nicht mehr – dieselbe Datei erneut. Ausgabe: „a=<status> b=<status> | <Meldung b>“
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
		$settings->getCursor(); // App-Config ist jetzt im Prozess-Cache
		file_put_contents('/tmp/s20-ready', '1');
		for ($i = 0; $i < 300 && !file_exists('/tmp/s20-go'); $i++) {
			usleep(100_000);
		}
		$after = $settings->isRootBlocked($argv[2]) ? 1 : 0;
		$settings->blockRoot($argv[3], 'Harness P', 'proc.php', 300);
		echo "before=$before after=$after\n";
		break;
	case 'samesecond':
		\OC_App::loadApps(); // files_trashbin, groupfolders – wie occ
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
		// am Anfang einer Sekunde beginnen, damit B ohne Warten in dieselbe Sekunde fiele
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
		// Trashbin::move2trash holt den Anbieter bei jedem Aufruf per Server::get
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
	default:
		fwrite(STDERR, "unbekannter Modus\n");
		exit(2);
}
