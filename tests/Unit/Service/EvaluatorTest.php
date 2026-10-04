<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\FolderRetention\Model\Basis;
use OCA\FolderRetention\Model\Decision;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Model\RetentionRule;
use OCA\FolderRetention\Model\RuleSet;
use OCA\FolderRetention\Model\Scope;
use OCA\FolderRetention\Service\Evaluator;
use OCA\FolderRetention\Service\RuleResolver;
use PHPUnit\Framework\TestCase;

class EvaluatorTest extends TestCase {
	private Evaluator $evaluator;
	private DateTimeZone $tz;
	private RuleSet $rules;
	private int $now;

	protected function setUp(): void {
		$this->evaluator = new Evaluator(new RuleResolver());
		$this->tz = new DateTimeZone('Europe/Berlin');
		$this->now = $this->ts('2026-12-31 12:00');
		$this->rules = new RuleSet(
			new RetentionRule(1, null, Period::of(1, PeriodUnit::Month), null),
			[
				10 => new RetentionRule(2, 10, Period::never(), Scope::Inherit),
				20 => new RetentionRule(3, 20, Period::of(1, PeriodUnit::Week), Scope::Inherit, Basis::Modified),
			],
		);
	}

	private function ts(string $d): int {
		return (new DateTimeImmutable($d, $this->tz))->getTimestamp();
	}

	private function file(int $parent, ?int $created, ?int $upload, int $mtime): FileRow {
		return new FileRow(99, 1, $parent, 'x/y.txt', $mtime, $created, $upload);
	}

	public function testDefaultRuleUsesUploadTime(): void {
		// Erstellzeit des Clients (Jahre zurück) zählt nicht – nur die Ablage in Nextcloud
		$f = $this->file(5, $this->ts('2019-01-01 12:00'), $this->ts('2026-08-01 12:00'), $this->ts('2026-09-20 12:00'));
		$d = $this->evaluator->evaluate($f, [5, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame($this->ts('2026-09-01 12:00'), $d->expiresAt);
		$this->assertTrue($d->isDueAt($this->ts('2026-09-25 00:00')));
		$this->assertFalse($d->isDueAt($this->ts('2026-09-01 11:59')));
		$this->assertSame('upload', $d->reference->source);
	}

	public function testWithoutUploadTimeUsesFirstSeenNotMtime(): void {
		// files:scan bzw. Altbestand: alte mtime darf nicht sofort fällig machen
		$f = new FileRow(99, 1, 5, 'x/y.txt', $this->ts('2010-01-01'), $this->ts('2010-01-01'), 0, firstSeen: $this->ts('2026-12-01 12:00'));
		$d = $this->evaluator->evaluate($f, [5, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame('seen', $d->reference->source);
		$this->assertSame($this->ts('2027-01-01 12:00'), $d->expiresAt);
		$this->assertFalse($d->isDueAt($this->now));
	}

	public function testWithoutUploadTimeAndFirstSeenIsNeverDue(): void {
		$f = $this->file(5, $this->ts('2010-01-01'), null, $this->ts('2010-01-01'));
		$d = $this->evaluator->evaluate($f, [5, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame(Decision::SKIP_NO_DATE, $d->skipReason);
	}

	public function testFutureMtimeIsCappedToNow(): void {
		$f = $this->file(20, null, null, $this->ts('2099-01-01'));
		$d = $this->evaluator->evaluate($f, [20, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame($this->now, $d->reference->timestamp);
		$this->assertFalse($d->isDueAt($this->now));
	}

	public function testRestoredFileCountsFromLastDeletion(): void {
		// Datei wurde gelöscht und am 2026-12-30 wiederhergestellt: Bezugsdatum = Löschung, nicht Upload
		$f = new FileRow(99, 1, 5, 'x/y.txt', $this->ts('2026-01-01'), null, $this->ts('2026-01-01'), lastDeleted: $this->ts('2026-12-30 03:00'));
		$d = $this->evaluator->evaluate($f, [5, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame('restored', $d->reference->source);
		$this->assertFalse($d->isDueAt($this->now), 'nächste Nacht nicht erneut löschen');
		$this->assertSame($this->ts('2027-01-30 03:00'), $d->expiresAt);
	}

	public function testFileRestoredLaterCountsFromFirstSeenAfterRestore(): void {
		// Gelöscht 2026-11-01, erst am 2026-12-30 wiederhergestellt und gesehen (Monatsfrist)
		$f = new FileRow(99, 1, 5, 'x/y.txt', $this->ts('2026-01-01'), null, $this->ts('2026-01-01'),
			firstSeen: $this->ts('2026-12-30 03:00'), lastDeleted: $this->ts('2026-11-01 03:00'));
		$d = $this->evaluator->evaluate($f, [5, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame('restored', $d->reference->source);
		$this->assertFalse($d->isDueAt($this->now), 'Wiederherstellung später als eine Frist nach der Löschung');
		$this->assertSame($this->ts('2027-01-30 03:00'), $d->expiresAt);
	}

	public function testNeverRuleIsNeverDue(): void {
		$f = $this->file(11, 1, null, 1);
		$d = $this->evaluator->evaluate($f, [11, 10, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertNull($d->expiresAt);
		$this->assertSame(Decision::SKIP_NEVER, $d->skipReason);
		$this->assertFalse($d->isDueAt(PHP_INT_MAX));
	}

	public function testModifiedBasisIgnoresCreationTime(): void {
		$f = $this->file(20, $this->ts('2020-01-01'), null, $this->ts('2026-09-20 12:00'));
		$d = $this->evaluator->evaluate($f, [20, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame($this->ts('2026-09-27 12:00'), $d->expiresAt);
	}

	public function testPersonalFilesSkippedWhenDefaultDoesNotApply(): void {
		$f = $this->file(5, 1, null, 1);
		$d = $this->evaluator->evaluate($f, [5, 1], $this->rules, false, $this->tz, $this->now);
		$this->assertSame(Decision::SKIP_PERSONAL, $d->skipReason);
		$this->assertFalse($d->isDueAt(PHP_INT_MAX));
	}

	public function testExplicitRuleAppliesEvenWhenDefaultDoesNot(): void {
		// Eine Ordnerregel in einem Home-Verzeichnis greift auch bei ausgeschalteter Personal-Option
		$f = $this->file(20, null, null, $this->ts('2026-09-01'));
		$d = $this->evaluator->evaluate($f, [20, 1], $this->rules, false, $this->tz, $this->now);
		$this->assertNull($d->skipReason);
		$this->assertTrue($d->isDueAt($this->ts('2026-09-25')));
	}

	public function testNoUsableDateIsNeverDue(): void {
		$f = $this->file(5, 0, 0, 0);
		$d = $this->evaluator->evaluate($f, [5, 1], $this->rules, true, $this->tz, $this->now);
		$this->assertSame(Decision::SKIP_NO_DATE, $d->skipReason);
		$this->assertFalse($d->isDueAt(PHP_INT_MAX));
	}
}
