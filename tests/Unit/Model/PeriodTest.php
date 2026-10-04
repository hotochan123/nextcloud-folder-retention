<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Tests\Unit\FakeL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PeriodTest extends TestCase {
	private DateTimeZone $tz;

	protected function setUp(): void {
		$this->tz = new DateTimeZone('Europe/Berlin');
	}

	private function ts(string $local): int {
		return (new DateTimeImmutable($local, $this->tz))->getTimestamp();
	}

	/**
	 * @return iterable<string, array{int, PeriodUnit, string, string}>
	 */
	public static function expiryCases(): iterable {
		yield '1 Tag' => [1, PeriodUnit::Day, '2026-09-25 10:00', '2026-09-26 10:00'];
		yield '1 Woche' => [1, PeriodUnit::Week, '2026-09-25 10:00', '2026-10-02 10:00'];
		yield '2 Wochen' => [2, PeriodUnit::Week, '2026-09-25 10:00', '2026-10-09 10:00'];
		yield '1 Monat' => [1, PeriodUnit::Month, '2026-09-25 10:00', '2026-10-25 10:00'];
		yield 'Monatsende 31.01. → 28.02.' => [1, PeriodUnit::Month, '2026-01-31 10:00', '2026-02-28 10:00'];
		yield 'Schaltjahr 31.01. → 29.02.' => [1, PeriodUnit::Month, '2028-01-31 10:00', '2028-02-29 10:00'];
		yield '31.08. → 30.09.' => [1, PeriodUnit::Month, '2026-08-31 10:00', '2026-09-30 10:00'];
		yield 'Jahreswechsel' => [2, PeriodUnit::Month, '2026-12-15 10:00', '2027-02-15 10:00'];
		yield '12 Monate' => [12, PeriodUnit::Month, '2026-03-01 00:00', '2027-03-01 00:00'];
		// Zeitumstellung 25.10.2026: Uhrzeit bleibt kalendarisch gleich (Tag hat 25 h)
		yield 'über Zeitumstellung' => [1, PeriodUnit::Day, '2026-10-24 12:00', '2026-10-25 12:00'];
	}

	#[DataProvider('expiryCases')]
	public function testExpiresAt(int $value, PeriodUnit $unit, string $from, string $expected): void {
		$p = Period::of($value, $unit);
		$this->assertSame($this->ts($expected), $p->expiresAt($this->ts($from), $this->tz));
	}

	public function testNeverHasNoExpiry(): void {
		$this->assertNull(Period::never()->expiresAt(time(), $this->tz));
		$this->assertTrue(Period::of(5, PeriodUnit::Never)->isNever());
	}

	public function testRejectsZero(): void {
		$this->expectException(InvalidArgumentException::class);
		Period::of(0, PeriodUnit::Day);
	}

	public function testRejectsTooLarge(): void {
		$this->expectException(InvalidArgumentException::class);
		Period::of(Period::MAX_VALUE + 1, PeriodUnit::Day);
	}

	public function testLabels(): void {
		$de = FakeL10N::de();
		$this->assertSame('1 Monat', Period::of(1, PeriodUnit::Month)->label($de));
		$this->assertSame('3 Monate', Period::of(3, PeriodUnit::Month)->label($de));
		$this->assertSame('2 Wochen', Period::of(2, PeriodUnit::Week)->label($de));
		$this->assertSame('1 Woche', Period::of(1, PeriodUnit::Week)->label($de));
		$this->assertSame('1 Tag', Period::of(1, PeriodUnit::Day)->label($de));
		$this->assertSame('5 Tage', Period::of(5, PeriodUnit::Day)->label($de));
		$this->assertSame('Nie löschen', Period::never()->label($de));
	}

	public function testEnglishLabels(): void {
		$en = FakeL10N::en();
		$this->assertSame('1 month', Period::of(1, PeriodUnit::Month)->label($en));
		$this->assertSame('3 months', Period::of(3, PeriodUnit::Month)->label($en));
		$this->assertSame('1 week', Period::of(1, PeriodUnit::Week)->label($en));
		$this->assertSame('2 days', Period::of(2, PeriodUnit::Day)->label($en));
		$this->assertSame('Never delete', Period::never()->label($en));
	}
}
