<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use OCA\FolderRetention\Db\LogMapper;
use OCA\FolderRetention\Db\Rule;
use OCA\FolderRetention\Db\RuleMapper;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Default rules on fresh install and update: new ones always "Never", existing ones stay.
 */
class RuleServiceTest extends TestCase {
	private RuleMapper&MockObject $mapper;
	private LogMapper&MockObject $logMapper;
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
		// IDBConnection cannot be mocked without Doctrine; the default rules do not need it
		$service = (new \ReflectionClass(RuleService::class))->newInstanceWithoutConstructor();
		$this->logMapper = $this->createMock(LogMapper::class);
		foreach (['mapper' => $this->mapper, 'time' => $time, 'settings' => $settings, 'logMapper' => $this->logMapper] as $name => $value) {
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
		// Prod: general 1 month, personal never – deliberately configured this way
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
		// Update from ≤ 0.5 with a retention period on the general rule, "personal too" switch off
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

	public function testDeletingARuleSupersedesItsSimulatedHits(): void {
		$service = $this->service();
		$rule = $this->rule(null, 1, PeriodUnit::Month);
		$rule->setFolderId(42);
		$rule->setId(7);
		$this->mapper->method('findByFolderId')->with(42)->willReturn($rule);
		$this->mapper->expects($this->once())->method('delete')->with($rule);
		$this->logMapper->expects($this->once())->method('supersedeByRule')->with(7, 1000);

		$this->assertTrue($service->delete(42));
	}

	public function testDeletingAMissingRuleSupersedesNothing(): void {
		$service = $this->service();
		$this->mapper->method('findByFolderId')->willReturn(null);
		$this->logMapper->expects($this->never())->method('supersedeByRule');

		$this->assertFalse($service->delete(42));
	}
}
