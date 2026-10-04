<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use OCA\FolderRetention\Db\Rule;
use OCA\FolderRetention\Db\RuleMapper;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Standardregeln bei Neuinstallation und Update: neu immer „Nie“, Vorhandenes bleibt.
 */
class RuleServiceTest extends TestCase {
	private RuleMapper&MockObject $mapper;
	private bool $includePersonal = false;
	/** @var array{general: ?Rule, personal: ?Rule} */
	private array $existing = ['general' => null, 'personal' => null];
	/** @var list<Rule> */
	private array $inserted = [];

	private function service(): RuleService {
		$this->mapper = $this->createMock(RuleMapper::class);
		$this->mapper->method('findDefault')->willReturnCallback(fn (bool $personal = false) => $this->existing[$personal ? 'personal' : 'general']);
		$this->mapper->method('insert')->willReturnCallback(function (Rule $r) {
			$this->inserted[] = $r;
			$this->existing[$r->getTarget() === Rule::TARGET_PERSONAL ? 'personal' : 'general'] = $r;
			return $r;
		});
		$this->mapper->method('findAll')->willReturnCallback(fn () => array_values(array_filter($this->existing)));

		$settings = $this->createMock(Settings::class);
		$settings->method('includePersonal')->willReturnCallback(fn () => $this->includePersonal);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		// IDBConnection lässt sich ohne Doctrine nicht mocken; die Standardregeln brauchen es nicht
		$service = (new \ReflectionClass(RuleService::class))->newInstanceWithoutConstructor();
		foreach (['mapper' => $this->mapper, 'time' => $time, 'settings' => $settings] as $name => $value) {
			(new \ReflectionProperty(RuleService::class, $name))->setValue($service, $value);
		}
		return $service;
	}

	private function rule(?string $target, ?int $value, PeriodUnit $unit): Rule {
		$r = new Rule();
		$r->setFolderId(null);
		$r->setTarget($target);
		$r->setPeriodValue($value);
		$r->setPeriodUnit($unit->value);
		$r->setScope(null);
		$r->setBasis('created');
		return $r;
	}

	public function testFreshInstallCreatesBothDefaultsAsNever(): void {
		$service = $this->service();
		$service->ensureDefault();
		$service->ensurePersonalDefault();

		$this->assertCount(2, $this->inserted);
		foreach ($this->inserted as $rule) {
			$this->assertSame('never', $rule->getPeriodUnit());
			$this->assertNull($rule->getPeriodValue());
		}
		$snapshot = $service->snapshot();
		$this->assertTrue($snapshot->default->period->isNever());
		$this->assertTrue($snapshot->personal->period->isNever());
	}

	public function testExistingRulesAreNotTouchedOnUpdate(): void {
		// Prod: allgemein 1 Monat, persönlich nie – bewusst so eingestellt
		$this->existing['general'] = $this->rule(null, 1, PeriodUnit::Month);
		$this->existing['personal'] = $this->rule(Rule::TARGET_PERSONAL, null, PeriodUnit::Never);
		$service = $this->service();
		$this->mapper->expects($this->never())->method('update');

		$service->ensureDefault();
		$service->ensurePersonalDefault();

		$this->assertSame([], $this->inserted);
		$this->assertSame('month', $this->existing['general']->getPeriodUnit());
	}

	public function testUpgradeWithoutPersonalRuleAddsNeverForPersonal(): void {
		// Update von ≤ 0.5 mit Frist an der allgemeinen Regel, Schalter „auch persönlich“ aus
		$this->existing['general'] = $this->rule(null, 1, PeriodUnit::Month);
		$this->service()->ensurePersonalDefault();

		$this->assertCount(1, $this->inserted);
		$this->assertSame(Rule::TARGET_PERSONAL, $this->inserted[0]->getTarget());
		$this->assertSame('never', $this->inserted[0]->getPeriodUnit());
	}

	public function testUpgradeWithIncludePersonalKeepsGeneralPeriod(): void {
		$this->existing['general'] = $this->rule(null, 2, PeriodUnit::Week);
		$this->includePersonal = true;
		$this->service()->ensurePersonalDefault();

		$this->assertSame('week', $this->inserted[0]->getPeriodUnit());
		$this->assertSame(2, $this->inserted[0]->getPeriodValue());
	}
}
