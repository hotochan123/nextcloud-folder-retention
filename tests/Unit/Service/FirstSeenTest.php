<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use OCA\FolderRetention\Service\FirstSeen;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Ableitung der Grenze aus seen_since (SQL-Aufbau; gegen echte Datenbank prüft der Harness S24).
 */
class FirstSeenTest extends TestCase {
	/** @var array<string, int|null> „max:lte“ bzw. „min:gt“ → Ergebnis */
	private array $answers = [];
	/** @var list<string> */
	private array $queries = [];

	private function firstSeen(): FirstSeen {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () {
			$state = ['fn' => '', 'cmp' => ''];
			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'from', 'where'] as $m) {
				$qb->method($m)->willReturnSelf();
			}
			$qb->method('createNamedParameter')->willReturn(':p');
			$func = $this->createMock(IFunctionBuilder::class);
			foreach (['max', 'min'] as $fn) {
				$func->method($fn)->willReturnCallback(function (string $col) use (&$state, $fn) {
					$this->assertSame('file_id', $col);
					$state['fn'] = $fn;
					return $this->createMock(IQueryFunction::class);
				});
			}
			$qb->method('func')->willReturn($func);
			$expr = $this->createMock(IExpressionBuilder::class);
			foreach (['lte', 'gt'] as $cmp) {
				$expr->method($cmp)->willReturnCallback(function (string $col) use (&$state, $cmp) {
					$this->assertSame('first_seen', $col);
					$state['cmp'] = $cmp;
					return 'x';
				});
			}
			$qb->method('expr')->willReturn($expr);
			$qb->method('executeQuery')->willReturnCallback(function () use (&$state) {
				$key = $state['fn'] . ':' . $state['cmp'];
				$this->queries[] = $key;
				$result = $this->createMock(IResult::class);
				// leere Tabelle: Aggregat liefert NULL
				$result->method('fetchOne')->willReturn($this->answers[$key] ?? null);
				return $result;
			});
			return $qb;
		});
		return new FirstSeen($db);
	}

	public function testJustBelowFirstIdSeenAfterTimestamp(): void {
		$this->answers = ['max:lte' => 120, 'min:gt' => 90];
		$this->assertSame(89, $this->firstSeen()->lastIdSeenUntil(1000));
		$this->assertSame(['max:lte', 'min:gt'], $this->queries);
	}

	public function testHighestSeenUntilWhenLowerThanFirstLaterSeen(): void {
		$this->answers = ['max:lte' => 120, 'min:gt' => 300];
		$this->assertSame(120, $this->firstSeen()->lastIdSeenUntil(1000));
	}

	/**
	 * Zyklus 1 unter 0.8.0: Bereich A um T0 gescannt (IDs bis 100), um T1 entsteht in A die
	 * Kopie 150 einer Datei von 2020 (erbt die Upload-Zeit), Bereich B um T2 gescannt (neue
	 * Datei 160), Zyklusende T3 = seen_since. Die Kopie sieht erst Zyklus 2 (T4 > T3) – unter
	 * 0.8.0 neu und geschützt. Die Grenze muss unter 150 liegen, nicht bei 160.
	 */
	public function testCopyFromFirstCycleStaysNew(): void {
		[$t0, $t2, $t3, $t4] = [1000, 1020, 1030, 1040];
		$seen = [5 => $t0, 100 => $t0, 160 => $t2, 150 => $t4];
		$this->answers = [
			'max:lte' => max(array_keys(array_filter($seen, fn (int $at) => $at <= $t3))),
			'min:gt' => min(array_keys(array_filter($seen, fn (int $at) => $at > $t3))),
		];
		$mark = $this->firstSeen()->lastIdSeenUntil($t3);
		$this->assertSame(149, $mark);
		$this->assertGreaterThan($mark, 150, 'Kopie bleibt neu (Bezug ab erstem Sehen)');
		$this->assertLessThanOrEqual($mark, 100, 'Bestand aus A bleibt Bestand');
	}

	/**
	 * Nach seen_since (T0, höchste bis dahin gesehene ID 900): Zyklus 2 scannt alice, danach
	 * legt sie per COPY die Kopie 1000 einer 700 Tage alten Datei an (erbt die Upload-Zeit,
	 * in diesem Zyklus nicht mehr gesehen), dann lädt bob 1001 hoch – gesehen nach T0. Update:
	 * Die Kopie war beim Update ungesehen und muss neu bleiben – Grenze unter 1000, nicht bei 1000.
	 */
	public function testCopyUnseenAtUpdateStaysNew(): void {
		$t0 = 2000;
		$seen = [5 => 1500, 900 => $t0, 1001 => 2100]; // Kopie 1000 fehlt: noch nicht gesehen
		$this->answers = [
			'max:lte' => max(array_keys(array_filter($seen, fn (int $at) => $at <= $t0))),
			'min:gt' => min(array_keys(array_filter($seen, fn (int $at) => $at > $t0))),
		];
		$mark = $this->firstSeen()->lastIdSeenUntil($t0);
		$this->assertSame(900, $mark);
		$this->assertGreaterThan($mark, 1000, 'ungesehene Kopie bleibt neu');
		$this->assertGreaterThan($mark, 1001, 'unter 0.8.0 neu (gt) bleibt neu');
	}

	public function testNothingSeenAfterTimestampGivesHighestSeenUntil(): void {
		$this->answers = ['max:lte' => 120];
		$this->assertSame(120, $this->firstSeen()->lastIdSeenUntil(1000));
		$this->assertSame(['max:lte', 'min:gt'], $this->queries);
	}

	public function testNothingSeenUntilTimestampGivesJustBelowFirstLaterSeen(): void {
		$this->answers = ['min:gt' => 50];
		$this->assertSame(49, $this->firstSeen()->lastIdSeenUntil(1000));
	}

	public function testFirstLaterSeenIdOneGivesZero(): void {
		$this->answers = ['min:gt' => 1, 'max:lte' => 120];
		$this->assertSame(0, $this->firstSeen()->lastIdSeenUntil(1000));
	}

	public function testEmptyTableGivesNull(): void {
		$this->assertNull($this->firstSeen()->lastIdSeenUntil(1000));
	}
}
