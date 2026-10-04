<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Model;

use OCA\FolderRetention\Model\Basis;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\ReferenceDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReferenceDateTest extends TestCase {
	private const NOW = 10_000;

	/**
	 * @return iterable<string, array{Basis, ?int, ?int, ?int, ?int, ?int, ?int, ?string}>
	 *   basis, creation_time, upload_time, mtime, first_seen, letzte Löschung, erwarteter Timestamp, erwartete Quelle
	 */
	public static function cases(): iterable {
		// basis=created = seit Ablage in Nextcloud: max(upload_time, creation_time)
		yield 'created: upload_time' => [Basis::Created, null, 2000, 3000, null, null, 2000, 'upload'];
		yield 'created: alte X-OC-CTime zählt nicht' => [Basis::Created, 1000, 2000, 3000, null, null, 2000, 'upload'];
		yield 'created: creation_time nach Upload gewinnt' => [Basis::Created, 2500, 2000, 3000, null, null, 2500, 'created'];
		yield 'created: creation_time 0' => [Basis::Created, 0, 2000, 3000, null, null, 2000, 'upload'];
		yield 'created: negativ gilt als nicht gesetzt' => [Basis::Created, -5, 2000, 3000, null, null, 2000, 'upload'];
		// Ohne upload_time (files:scan, Altbestand): „zuerst gesehen“, nie mtime oder creation_time
		yield 'created: ohne upload_time → first_seen' => [Basis::Created, 1000, 0, 3000, 5000, null, 5000, 'seen'];
		yield 'created: upload_time null → first_seen' => [Basis::Created, null, null, 3000, 5000, null, 5000, 'seen'];
		yield 'created: ohne upload_time und first_seen → keins' => [Basis::Created, 1000, 0, 3000, null, null, null, null];
		yield 'created: nichts brauchbar' => [Basis::Created, 0, 0, 0, null, null, null, null];
		// Zukunftswerte werden auf jetzt gekappt
		yield 'created: upload_time in der Zukunft' => [Basis::Created, null, 99_999, 3000, null, null, self::NOW, 'upload'];
		yield 'created: creation_time in der Zukunft' => [Basis::Created, 99_999, 2000, 3000, null, null, self::NOW, 'created'];
		yield 'modified: mtime in der Zukunft' => [Basis::Modified, null, null, 99_999, null, null, self::NOW, 'mtime'];
		// basis=modified ignoriert creation/upload/first_seen
		yield 'modified: mtime' => [Basis::Modified, 1000, 2000, 3000, 4000, null, 3000, 'mtime'];
		yield 'modified: mtime 0 → kein Fallback' => [Basis::Modified, 1000, 2000, 0, 4000, null, null, null];
		// Aus dem Papierkorb wiederhergestellt: frühestens ab der letzten Löschung
		yield 'created: wiederhergestellt' => [Basis::Created, null, 2000, 3000, null, 7000, 7000, 'restored'];
		yield 'modified: wiederhergestellt' => [Basis::Modified, null, null, 3000, null, 7000, 7000, 'restored'];
		yield 'Löschung vor Bezugsdatum ändert nichts' => [Basis::Created, null, 2000, 3000, null, 1500, 2000, 'upload'];
		yield 'wiederhergestellt ohne sonstiges Datum bleibt ohne' => [Basis::Created, null, 0, 3000, null, 7000, null, null];
		// Wiederherstellung erst lange nach der Löschung: ab dem ersten Sehen danach
		yield 'created: später wiederhergestellt → erstes Sehen danach' => [Basis::Created, null, 2000, 3000, 9000, 7000, 9000, 'restored'];
		yield 'modified: später wiederhergestellt → erstes Sehen danach' => [Basis::Modified, null, null, 3000, 9000, 7000, 9000, 'restored'];
		yield 'erstes Sehen vor der Löschung zählt nicht' => [Basis::Created, null, 2000, 3000, 6000, 7000, 7000, 'restored'];
		yield 'erstes Sehen nach Löschung in der Zukunft gekappt' => [Basis::Created, null, 2000, 3000, 99_999, 7000, self::NOW, 'restored'];
	}

	/**
	 * Kopien erben upload_time/creation_time des Originals: „zuerst gesehen“ zählt neben
	 * upload_time nur für Datei-IDs über der beim Update gemerkten höchsten ID (neu).
	 *   upload_time, first_seen, neu, erwarteter Timestamp, erwartete Quelle
	 */
	public static function copyCases(): iterable {
		yield 'neue Datei-ID (Kopie): ab erstem Sehen' => [2000, 6000, true, 6000, 'seen'];
		yield 'Bestand: Upload-Zeit, auch wenn erst spät gesehen' => [2000, 6000, false, 2000, 'upload'];
		yield 'Upload nach erstem Sehen gewinnt' => [7000, 6000, true, 7000, 'upload'];
		yield 'erstes Sehen in der Zukunft wird gekappt' => [2000, 99_999, true, self::NOW, 'seen'];
	}

	#[DataProvider('copyCases')]
	public function testCopiesCountFromFirstSeen(int $upload, int $seen, bool $isNew, int $expectedTs, string $expectedSource): void {
		$ref = ReferenceDate::fromFileCache(Basis::Created, null, $upload, 3000, $seen, null, self::NOW, $isNew);
		$this->assertSame($expectedTs, $ref?->timestamp);
		$this->assertSame($expectedSource, $ref?->source);
	}

	/**
	 * Befund: Regel 7 Tage, an Tag 0 gelöscht, an Tag 10 zurückgeholt – nicht erneut fällig,
	 * Frist ab dem ersten Sehen nach der Wiederherstellung (auch bei Bestand mit upload_time).
	 */
	public function testRestoredLongAfterDeletionIsNotDueAgain(): void {
		$day = 86400;
		$now = 1_000 * $day;
		$ref = ReferenceDate::fromFileCache(Basis::Created, $now - 400 * $day, $now - 400 * $day, $now - 400 * $day, $now, $now - 10 * $day, $now, false);
		$this->assertSame('restored', $ref?->source);
		$this->assertSame($now, $ref->timestamp);
		$this->assertGreaterThan($now, $ref->timestamp + 7 * $day, 'nicht fällig');
	}

	public function testModifiedIgnoresFirstSeenOfCopies(): void {
		$ref = ReferenceDate::fromFileCache(Basis::Modified, null, 2000, 3000, 6000, null, self::NOW, true);
		$this->assertSame(3000, $ref?->timestamp);
	}

	/**
	 * Grenze über die Datei-ID: Kopien bekommen immer eine neue, höhere ID – auch wenn sie im
	 * ersten Zyklus nach dem Update auftauchen, bevor dieser fertig ist.
	 */
	public function testFileRowIsNewAboveMark(): void {
		$copy = (new FileRow(21, 1, 1, 'files/kopie.pdf', 3000, 2000, 2000))->with(6000, null, 20);
		$this->assertTrue($copy->isNewSinceMark());
		$this->assertSame('seen', $copy->referenceDate(Basis::Created, self::NOW)?->source);

		$old = (new FileRow(20, 1, 1, 'files/alt.pdf', 3000, 2000, 2000))->with(6000, null, 20);
		$this->assertFalse($old->isNewSinceMark(), 'genau die Grenze ist Bestand');
		$this->assertSame(2000, $old->referenceDate(Basis::Created, self::NOW)?->timestamp);

		$unknown = (new FileRow(21, 1, 1, 'files/x.pdf', 3000, 2000, 2000))->with(6000, null, 0);
		$this->assertTrue($unknown->isNewSinceMark(), 'Grenze 0 (unbekannt): vorsichtig alles neu');
	}

	#[DataProvider('cases')]
	public function testReferenceDate(Basis $basis, ?int $created, ?int $upload, ?int $mtime, ?int $seen, ?int $lastDeleted, ?int $expectedTs, ?string $expectedSource): void {
		$ref = ReferenceDate::fromFileCache($basis, $created, $upload, $mtime, $seen, $lastDeleted, self::NOW);
		if ($expectedTs === null) {
			$this->assertNull($ref);
			return;
		}
		$this->assertNotNull($ref);
		$this->assertSame($expectedTs, $ref->timestamp);
		$this->assertSame($expectedSource, $ref->source);
	}
}
