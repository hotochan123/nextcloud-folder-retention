<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Service\Settings;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../stubs/Doctrine.php';

/**
 * Settings mit der Sperrtabelle (folder_retention_block) im Speicher: Einfügen ohne
 * Überschreiben und DELETE mit Bedingung wie in der Datenbank. Mehrere Instanzen teilen sich
 * die Tabelle – wie mehrere Prozesse.
 */
final class InMemoryBlockSettings extends Settings {
	public function __construct(IAppConfig $appConfig, IConfig $config, IDBConnection $db, private \ArrayObject $table) {
		parent::__construct($appConfig, $config, $db);
	}

	protected function loadBlocks(): array {
		$rows = array_values($this->table->getArrayCopy());
		usort($rows, fn (array $a, array $b) => [$a['at'], $a['key']] <=> [$b['at'], $b['key']]);
		return $rows;
	}

	protected function insertBlock(string $key, string $label, string $reason, int $at): void {
		if (!isset($this->table[$key])) {
			$this->table[$key] = ['key' => $key, 'label' => $label, 'reason' => $reason, 'at' => $at];
		}
	}

	protected function deleteBlock(string $key, ?int $at): int {
		if (!isset($this->table[$key]) || ($at !== null && $this->table[$key]['at'] !== $at)) {
			return 0;
		}
		unset($this->table[$key]);
		return 1;
	}
}

/**
 * Sicherheitssperre unabhängig von der Art des Bereichs, frisch gelesen statt aus dem
 * Prozess-Cache, „zuerst gesehen“-Startpunkt, Laufsperre (SQL-Aufbau). Die Laufsperre gegen
 * eine echte Datenbank prüft der Harness (S10), die Sperrliste zusätzlich S19/S20.
 */
class SettingsTest extends TestCase {
	/** @var array<string, string|int> App-Config in der Datenbank */
	private array $values = [];
	private \ArrayObject $blockTable;
	/** @var list<string> an IQueryBuilder::createFunction übergebene Ausdrücke */
	private array $functions = [];
	/** Ergebnis von executeStatement im Laufsperren-Test */
	private int $affected = 1;

	protected function setUp(): void {
		$this->blockTable = new \ArrayObject();
	}

	/**
	 * Eine Instanz = ein Prozess: Die App-Config liest wie IAppConfig einmal und dann aus dem
	 * eigenen Cache, bis clearCache(); Schreiben geht in die Datenbank und den eigenen Cache.
	 */
	private function settings(): Settings {
		$cache = null;
		$read = function () use (&$cache): array {
			return $cache ??= $this->values;
		};
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => (string)($read()[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use (&$cache, $read) {
			$read();
			$this->values[$key] = $cache[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key) use (&$cache, $read) {
			$read();
			unset($this->values[$key], $cache[$key]);
		});
		$appConfig->method('getValueInt')->willReturnCallback(fn (string $app, string $key, int $default = 0) => (int)($read()[$key] ?? $default));
		$appConfig->method('setValueInt')->willReturnCallback(function (string $app, string $key, int $value) use (&$cache, $read) {
			$read();
			$this->values[$key] = $cache[$key] = $value;
			return true;
		});
		$appConfig->method('clearCache')->willReturnCallback(function () use (&$cache) {
			$cache = null;
		});
		return new InMemoryBlockSettings($appConfig, $this->createMock(IConfig::class), $this->db(), $this->blockTable);
	}

	/** Datenbank für die Laufsperre: Einfügen scheitert (Zeile da), UPDATE trifft $affected Zeilen */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('insertIgnoreConflict')->willReturn(0);
		$db->method('getQueryBuilder')->willReturnCallback(function () {
			$expr = $this->createMock(IExpressionBuilder::class);
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['update', 'set', 'where', 'andWhere', 'delete', 'select', 'from'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			// Oracle: Spalten sind klein geschrieben angelegt und müssen quotiert werden
			$qb->method('getColumnName')->willReturnCallback(fn (string $c) => '"' . $c . '"');
			$qb->method('createFunction')->willReturnCallback(function (string $call) {
				$this->functions[] = $call;
				return $this->createMock(\OCP\DB\QueryBuilder\IQueryFunction::class);
			});
			$qb->method('executeStatement')->willReturnCallback(fn () => $this->affected);
			return $qb;
		});
		return $db;
	}

	public function testBlockSurvivesSwitchBetweenPersonalAndWorkspace(): void {
		$settings = $this->settings();
		$workspace = new RetentionRoot(RetentionRoot::KIND_WORKSPACE, 5, 50, 'files', 'Funktionskonto', ['fk']);
		$home = new RetentionRoot(RetentionRoot::KIND_HOME, 5, 50, 'files', 'Persönlich · fk', ['fk']);

		$settings->blockRoot($workspace->blockKey(), $workspace->label, 'endgültig gelöscht', 1);

		$this->assertTrue($settings->isRootBlocked($home->blockKey()), 'Admin schaltet auf persönlich um – Sperre bleibt');
		$this->assertFalse($settings->isRootBlocked((new RetentionRoot(RetentionRoot::KIND_HOME, 6, 60, 'files', 'x', ['x']))->blockKey()));
	}

	public function testOldBlockKeysWithKindStillMatch(): void {
		$settings = $this->settings();
		$settings->blockRoot('workspace:0000000005:000000000050', 'Funktionskonto', 'alt', 1);
		$home = new RetentionRoot(RetentionRoot::KIND_HOME, 5, 50, 'files', 'Persönlich · fk', ['fk']);
		$this->assertTrue($settings->isRootBlocked($home->blockKey()));
	}

	public function testSeenMarkIsSetOnlyOnce(): void {
		$settings = $this->settings();
		$this->assertNull($settings->seenMaxFileId());
		$settings->initSeenMaxFileId(1000);
		$settings->initSeenMaxFileId(2000);
		$this->assertSame(1000, $settings->seenMaxFileId(), 'späterer Wert ließe neue Dateien als Bestand gelten');
	}

	public function testSeenMarkOnEmptyFileCacheTreatsEverythingAsNew(): void {
		$settings = $this->settings();
		$settings->initSeenMaxFileId(0);
		$this->assertSame(1, $settings->seenMaxFileId());
	}

	public function testOlderCursorsStayReadable(): void {
		$settings = $this->settings();
		$this->values['job_cursor'] = json_encode(['root' => 'home:1', 'after' => 5]);
		$this->assertSame(['root' => 'home:1', 'after' => 5], $settings->getCursor());
		$this->values['job_cursor'] = json_encode(['root' => 'home:1', 'after' => 7, 'seen' => true]);
		$settings = $this->settings(); // anderer Prozess (der erste hält seinen Stand im Cache)
		$this->assertSame(['root' => 'home:1', 'after' => 7], $settings->getCursor());

		$settings->setCursor('home:1', 9);
		$this->assertSame(['root' => 'home:1', 'after' => 9], $settings->getCursor());
	}

	public function testUnblockOnlyRemovesNamedBlocks(): void {
		$settings = $this->settings();
		$settings->blockRoot('a', 'Bereich A', 'x', 100);
		// Admin sieht nur A; während die Seite offen ist, sperrt der Job B
		$settings->blockRoot('b', 'Bereich B', 'y', 200);

		$this->assertSame(['a'], $settings->unblockRoots(['a' => 100]));

		$this->assertFalse($settings->isRootBlocked('a'));
		$this->assertTrue($settings->isRootBlocked('b'), 'nie gesehene Sperre bleibt');
		$this->assertSame(['b'], array_keys($settings->blockedRoots()));
	}

	public function testUnblockKeepsBlockRenewedSinceDisplayed(): void {
		$settings = $this->settings();
		$settings->blockRoot('a', 'Bereich A', 'neu', 300); // angezeigt war die Sperre von 100

		$this->assertSame([], $settings->unblockRoots(['a' => 100]));
		$this->assertTrue($settings->isRootBlocked('a'));
		$this->assertSame(['a'], $settings->unblockRoots(['a' => null]), 'ohne Zeitpunkt (occ) gezielt aufheben');
		$this->assertSame([], $settings->blockedRoots());
	}

	public function testUnblockUnknownKeyChangesNothing(): void {
		$settings = $this->settings();
		$settings->blockRoot('a', 'Bereich A', 'x', 100);
		$this->assertSame([], $settings->unblockRoots(['zzz' => null]));
		$this->assertTrue($settings->isRootBlocked('a'));
	}

	public function testRunningProcessSeesBlockSetByAnotherProcess(): void {
		$cron = $this->settings();
		$occ = $this->settings();
		$this->assertFalse($cron->isRootBlocked('0000000005:000000000050'), 'cron.php hat die Liste schon gelesen');

		$occ->blockRoot('0000000005:000000000050', 'Bereich C', 'endgültig gelöscht', 100);

		$this->assertTrue($cron->isRootBlocked('0000000005:000000000050'), 'Sperre des anderen Laufs gilt sofort');
	}

	public function testBlocksOfOtherProcessesAreNeverOverwritten(): void {
		$cron = $this->settings();
		$occ = $this->settings();
		$web = $this->settings();
		$cron->blockedRoots();
		$web->blockedRoots();

		$occ->blockRoot('c', 'C', 'x', 100);
		$cron->blockRoot('d', 'D', 'y', 110); // Liste des Cron war veraltet
		$this->assertSame(['c', 'd'], array_keys($occ->blockedRoots()), 'Sperre C bleibt');

		// Admin hebt D auf (Seite zeigte D) – C kam im Web-Prozess nie an, bleibt trotzdem
		$this->assertSame(['d'], $web->unblockRoots(['d' => 110]));
		$this->assertSame(['c'], array_keys($cron->blockedRoots()));

		// Cron sperrt danach E – setzt das aufgehobene D nicht wieder
		$cron->blockRoot('e', 'E', 'z', 120);
		$this->assertSame(['c', 'e'], array_keys($web->blockedRoots()));
	}

	public function testLegacyBlocksFromAppConfigAreMigratedOnce(): void {
		$this->values['blocked_roots'] = json_encode([
			'workspace:0000000005:000000000050' => ['label' => 'Funktionskonto', 'reason' => 'alt', 'at' => 7],
		]);
		$settings = $this->settings();
		$home = new RetentionRoot(RetentionRoot::KIND_HOME, 5, 50, 'files', 'Persönlich · fk', ['fk']);

		$this->assertTrue($settings->isRootBlocked($home->blockKey()));
		$this->assertArrayNotHasKey('blocked_roots', $this->values, 'App-Config-Eintrag übernommen und entfernt');
		$this->assertSame(['workspace:0000000005:000000000050' => ['label' => 'Funktionskonto', 'reason' => 'alt', 'at' => 7]], $settings->blockedRoots());
	}

	public function testStaleLegacyCacheDoesNotRestoreLiftedBlock(): void {
		$this->values['blocked_roots'] = json_encode(['a' => ['label' => 'A', 'reason' => 'x', 'at' => 1]]);
		$stale = $this->settings();
		$stale->getCursor(); // Prozess hat die App-Config (samt alter Liste) im Cache
		$admin = $this->settings();
		$this->assertSame(['a'], $admin->unblockRoots(['a' => 1]), 'übernommen und aufgehoben');

		$this->assertFalse($stale->isRootBlocked('a'), 'veralteter Cache setzt die aufgehobene Sperre nicht wieder');
	}

	public function testRunLeaseQuotesColumnInRenewalCounter(): void {
		$settings = $this->settings();
		$this->assertNull($settings->acquireRunLease('occ', 't', 1000, 900), 'Zeile da, Übernahme per UPDATE');
		$this->assertTrue($settings->renewRunLease('t', 1100, 900));
		$this->assertSame(['"renewals" + 1', '"renewals" + 1'], $this->functions, 'Spaltenname per getColumnName (Oracle: ORA-00904)');
	}

	// --- Simulationsschalter frisch aus der Datenbank (Notbremse im laufenden Prozess) ---

	/** @return array<string, array{0: string|null|\Throwable, 1: bool}> */
	public static function simulationRows(): array {
		return [
			'kein Eintrag: Standard AN' => [null, true],
			'boolean 1' => ['1', true],
			'boolean 0' => ['0', false],
			'Text false' => ['false', false],
			'Text no' => ['no', false],
			'Text yes' => ['yes', true],
			'unbekannt: vorsichtshalber AN' => ['vielleicht', true],
			'DB-Fehler: vorsichtshalber AN' => [new \RuntimeException('DB weg'), true],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('simulationRows')]
	public function testSimulationIsReadFreshFromDatabase(string|null|\Throwable $row, bool $expected): void {
		$appConfig = $this->createMock(IAppConfig::class);
		// Prozess-Cache sagt AUS – zählen darf nur die Datenbank
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->expects($this->never())->method('clearCache');
		$settings = new class($appConfig, $this->createMock(IConfig::class), $this->createMock(IDBConnection::class), $row) extends Settings {
			public function __construct(IAppConfig $a, IConfig $c, IDBConnection $d, private string|null|\Throwable $row) {
				parent::__construct($a, $c, $d);
			}

			protected function loadSimulationValue(): ?string {
				if ($this->row instanceof \Throwable) {
					throw $this->row;
				}
				return $this->row;
			}
		};
		$this->assertFalse($settings->isSimulation(), 'Vorbedingung: Cache sagt AUS');
		$this->assertSame($expected, $settings->isSimulationFresh());
	}
}
