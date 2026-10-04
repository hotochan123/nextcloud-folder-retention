<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Model;

use DateTimeZone;
use OCA\FolderRetention\Model\Decision;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Model\RetentionRule;
use OCA\FolderRetention\Model\RuleSet;
use OCA\FolderRetention\Model\Scope;
use OCA\FolderRetention\Service\Evaluator;
use OCA\FolderRetention\Service\RuleResolver;
use OCA\FolderRetention\Tests\Unit\FakeL10N;
use PHPUnit\Framework\TestCase;

class RuleSetTest extends TestCase {
	private function set(): RuleSet {
		return new RuleSet(
			new RetentionRule(1, null, Period::of(1, PeriodUnit::Month), null),
			[50 => new RetentionRule(3, 50, Period::never(), Scope::Inherit)],
			new RetentionRule(2, null, Period::of(1, PeriodUnit::Week), null, personal: true),
		);
	}

	private function root(string $kind): RetentionRoot {
		return new RetentionRoot($kind, 1, 10, 'files', 'x', ['alice']);
	}

	public function testPersonalFoldersUsePersonalDefault(): void {
		$rs = $this->set()->forRoot($this->root(RetentionRoot::KIND_HOME));
		$res = (new RuleResolver())->resolve([20, 10], $rs->byFolderId, $rs->default);
		$this->assertSame(2, $res->rule->id);
		$this->assertTrue($res->isDefault());
		$this->assertSame('Standard persönlich: 1 Woche', $res->rule->logLabel(FakeL10N::de()));
		$this->assertSame('Personal default: 1 week', $res->rule->logLabel(FakeL10N::en()));
	}

	public function testTeamAndWorkspaceUseGeneralDefault(): void {
		foreach ([RetentionRoot::KIND_TEAM, RetentionRoot::KIND_WORKSPACE] as $kind) {
			$rs = $this->set()->forRoot($this->root($kind));
			$res = (new RuleResolver())->resolve([20, 10], $rs->byFolderId, $rs->default);
			$this->assertSame(1, $res->rule->id, $kind);
		}
	}

	public function testFolderRulesStillWinInPersonalFolders(): void {
		$rs = $this->set()->forRoot($this->root(RetentionRoot::KIND_HOME));
		$res = (new RuleResolver())->resolve([60, 50, 10], $rs->byFolderId, $rs->default);
		$this->assertSame(3, $res->rule->id);
	}

	public function testByIdFindsBothDefaults(): void {
		$this->assertSame(2, $this->set()->byId(2)?->id);
		$this->assertSame(1, $this->set()->byId(1)?->id);
	}

	public function testPersonalNeverExcludesPersonalFiles(): void {
		// Standard ab 0.8.0: persönlich „Nie“ – Ordnerregeln greifen trotzdem
		$set = new RuleSet(
			new RetentionRule(1, null, Period::of(1, PeriodUnit::Month), null),
			[50 => new RetentionRule(3, 50, Period::of(1, PeriodUnit::Day), Scope::Inherit)],
			new RetentionRule(2, null, Period::never(), null, personal: true),
		);
		$rs = $set->forRoot($this->root(RetentionRoot::KIND_HOME));
		$evaluator = new Evaluator(new RuleResolver());
		$tz = new DateTimeZone('UTC');
		$file = new FileRow(9, 1, 20, 'files/x', 1, null, 1);

		$d = $evaluator->evaluate($file, [20, 10], $rs, true, $tz, 1_000_000);
		$this->assertSame(Decision::SKIP_NEVER, $d->skipReason);
		$this->assertFalse($d->isDueAt(PHP_INT_MAX));

		$d = $evaluator->evaluate($file, [60, 50, 10], $rs, true, $tz, 1_000_000);
		$this->assertTrue($d->isDueAt(1_000_000), 'Ordnerregel im persönlichen Ordner gilt weiter');
	}

	public function testWithoutPersonalRuleHomeFallsBackToExcluded(): void {
		// Ohne persönliche Standardregel (Altstand) bleibt der Pfad „persönlich ausgenommen“ erreichbar
		$set = new RuleSet(new RetentionRule(1, null, Period::of(1, PeriodUnit::Day), null), []);
		$rs = $set->forRoot($this->root(RetentionRoot::KIND_HOME));
		$this->assertSame($set, $rs);
		$defaultApplies = !$this->root(RetentionRoot::KIND_HOME)->isHome() || $rs->personal !== null;
		$d = (new Evaluator(new RuleResolver()))->evaluate(new FileRow(9, 1, 20, 'files/x', 1, null, 1), [20, 10], $rs, $defaultApplies, new DateTimeZone('UTC'), 1_000_000);
		$this->assertSame(Decision::SKIP_PERSONAL, $d->skipReason);
	}
}
