<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Model\RetentionRule;
use OCA\FolderRetention\Model\Scope;
use OCA\FolderRetention\Service\RuleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test tree (folder IDs in parentheses, rules on the right):
 *
 *   /                         (1)
 *   ├── Academy               (10)  1 month, inherit
 *   │   ├── Projekte          (11)  –
 *   │   │   └── 2026          (12)  –
 *   │   └── Archiv            (13)  Never, inherit    ← nested exception
 *   │       ├── Alt           (14)  –
 *   │       └── Temp          (15)  1 day, inherit    ← exception within the exception
 *   │           └── X         (16)  –
 *   ├── QM-IT                 (20)  Never, here       ← this level only
 *   │   └── Entwürfe          (21)  –
 *   │       └── Tief          (22)  –
 *   ├── Kommunikation         (30)  2 weeks, inherit
 *   │   └── Presse            (31)  1 week, here      ← here below inherit
 *   │       └── Fotos         (32)  –
 *   │           └── Roh       (33)  3 days, here
 *   │               └── Neu   (34)  –
 *   └── Sonstiges             (40)  –
 */
class RuleResolverTest extends TestCase {
	private RuleResolver $resolver;
	private RetentionRule $default;
	/** @var array<int, RetentionRule> */
	private array $rules;

	/** Parent chain per folder: [folder, parent, …, root] */
	private const CHAINS = [
		1 => [1],
		10 => [10, 1], 11 => [11, 10, 1], 12 => [12, 11, 10, 1],
		13 => [13, 10, 1], 14 => [14, 13, 10, 1], 15 => [15, 13, 10, 1], 16 => [16, 15, 13, 10, 1],
		20 => [20, 1], 21 => [21, 20, 1], 22 => [22, 21, 20, 1],
		30 => [30, 1], 31 => [31, 30, 1], 32 => [32, 31, 30, 1], 33 => [33, 32, 31, 30, 1], 34 => [34, 33, 32, 31, 30, 1],
		40 => [40, 1],
	];

	protected function setUp(): void {
		$this->resolver = new RuleResolver();
		$this->default = new RetentionRule(1, null, Period::of(1, PeriodUnit::Month), null);
		$this->rules = [];
		foreach ([
			[100, 10, Period::of(1, PeriodUnit::Month), Scope::Inherit],
			[101, 13, Period::never(), Scope::Inherit],
			[102, 15, Period::of(1, PeriodUnit::Day), Scope::Inherit],
			[103, 20, Period::never(), Scope::Here],
			[104, 30, Period::of(2, PeriodUnit::Week), Scope::Inherit],
			[105, 31, Period::of(1, PeriodUnit::Week), Scope::Here],
			[106, 33, Period::of(3, PeriodUnit::Day), Scope::Here],
		] as [$id, $folder, $period, $scope]) {
			$this->rules[$folder] = new RetentionRule($id, $folder, $period, $scope);
		}
	}

	/**
	 * @return iterable<string, array{int, ?int, ?int}> parent folder of the file, expected rule ID (null = default), expected depth
	 */
	public static function fileCases(): iterable {
		// Rule directly on the parent folder
		yield 'Datei direkt in Academy' => [10, 100, 0];
		// Inheritance across several levels
		yield 'Academy/Projekte' => [11, 100, 1];
		yield 'Academy/Projekte/2026' => [12, 100, 2];
		// Nested exception overrides the outer rule
		yield 'Academy/Archiv' => [13, 101, 0];
		yield 'Academy/Archiv/Alt erbt Ausnahme' => [14, 101, 1];
		yield 'Ausnahme in der Ausnahme' => [15, 102, 0];
		yield 'unter Ausnahme in der Ausnahme' => [16, 102, 1];
		// scope=here: applies directly, but not to subfolders
		yield 'QM-IT direkt (here gilt)' => [20, 103, 0];
		yield 'QM-IT/Entwürfe fällt auf Standard' => [21, null, null];
		yield 'QM-IT/Entwürfe/Tief fällt auf Standard' => [22, null, null];
		// here below inherit: subfolders skip the here rule and inherit from further up
		yield 'Presse direkt (here)' => [31, 105, 0];
		yield 'Presse/Fotos überspringt here, erbt Kommunikation' => [32, 104, 2];
		yield 'Roh direkt (here)' => [33, 106, 0];
		yield 'Roh/Neu überspringt zwei here-Regeln' => [34, 104, 4];
		// no rule in the path
		yield 'Sonstiges → Standard' => [40, null, null];
		yield 'Wurzel → Standard' => [1, null, null];
	}

	#[DataProvider('fileCases')]
	public function testResolveForFile(int $parent, ?int $expectedRuleId, ?int $expectedDepth): void {
		$res = $this->resolver->resolve(self::CHAINS[$parent], $this->rules, $this->default);

		if ($expectedRuleId === null) {
			$this->assertTrue($res->isDefault());
			$this->assertSame($this->default, $res->rule);
			$this->assertNull($res->sourceFolderId());
		} else {
			$this->assertSame($expectedRuleId, $res->rule->id);
			$this->assertFalse($res->isDefault());
		}
		$this->assertSame($expectedDepth, $res->depth);
		$this->assertSame($expectedDepth === 0, $res->isOwn());
	}

	/**
	 * @return iterable<string, array{int, ?int}> folder, expected rule ID for its subfolders
	 */
	public static function childCases(): iterable {
		yield 'Academy vererbt sich selbst' => [10, 100];
		yield 'QM-IT (here) → Unterordner erben Standard' => [20, null];
		yield 'Presse (here) → Unterordner erben Kommunikation' => [31, 104];
		yield 'Roh (here) → Kommunikation' => [33, 104];
		yield 'Ordner ohne Regel → wie Datei darin' => [11, 100];
		yield 'Sonstiges → Standard' => [40, null];
	}

	#[DataProvider('childCases')]
	public function testResolveForChildren(int $folder, ?int $expectedRuleId): void {
		$res = $this->resolver->resolveForChildren(self::CHAINS[$folder], $this->rules, $this->default);
		$this->assertSame($expectedRuleId ?? $this->default->id, $res->rule->id);
	}

	public function testResolveForChildrenMatchesResolveOfRealChildWithoutRule(): void {
		// Consistency: what resolveForChildren(Q) says must match resolve(child of Q).
		foreach ([[20, 21], [31, 32], [33, 34], [10, 11], [13, 14]] as [$folder, $child]) {
			$forChildren = $this->resolver->resolveForChildren(self::CHAINS[$folder], $this->rules, $this->default);
			$actual = $this->resolver->resolve(self::CHAINS[$child], $this->rules, $this->default);
			$this->assertSame($actual->rule, $forChildren->rule, "Ordner $folder / Kind $child");
		}
	}

	public function testNoRulesAtAllGivesDefault(): void {
		$res = $this->resolver->resolve([5, 4, 3], [], $this->default);
		$this->assertTrue($res->isDefault());
	}

	public function testEmptyChainGivesDefault(): void {
		$res = $this->resolver->resolve([], $this->rules, $this->default);
		$this->assertTrue($res->isDefault());
	}

	public function testHereRuleOnDirectParentWinsEvenIfAncestorInherits(): void {
		// Presse (here, 1 week) sits below Kommunikation (inherit, 2 weeks)
		$res = $this->resolver->resolve(self::CHAINS[31], $this->rules, $this->default);
		$this->assertSame(PeriodUnit::Week, $res->rule->period->unit);
		$this->assertSame(1, $res->rule->period->value);
	}

	public function testNeverRuleIsReturnedLikeAnyOther(): void {
		$res = $this->resolver->resolve(self::CHAINS[14], $this->rules, $this->default);
		$this->assertTrue($res->rule->period->isNever());
	}

	public function testRulesForFoldersOutsideChainAreIgnored(): void {
		// Rules on sibling folders must have no effect
		$res = $this->resolver->resolve(self::CHAINS[40], $this->rules, $this->default);
		$this->assertTrue($res->isDefault());
	}

	public function testChainKeysAreNormalised(): void {
		// Non-contiguous array keys must not distort the depth
		$res = $this->resolver->resolve([5 => 11, 9 => 10, 2 => 1], $this->rules, $this->default);
		$this->assertSame(1, $res->depth);
	}

	public function testRejectsNonDefaultAsDefault(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->resolver->resolve([10], $this->rules, $this->rules[10]);
	}

	public function testRejectsRuleStoredUnderWrongKey(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->resolver->resolve([99], [99 => $this->rules[10]], $this->default);
	}

	public function testDefaultRuleCannotHaveScope(): void {
		$this->expectException(InvalidArgumentException::class);
		new RetentionRule(1, null, Period::never(), Scope::Inherit);
	}

	public function testFolderRuleNeedsScope(): void {
		$this->expectException(InvalidArgumentException::class);
		new RetentionRule(1, 10, Period::never(), null);
	}
}
