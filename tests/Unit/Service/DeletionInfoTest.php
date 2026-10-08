<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use DateTimeZone;
use OCA\FolderRetention\Model\Basis;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Model\RetentionRule;
use OCA\FolderRetention\Model\RuleSet;
use OCA\FolderRetention\Model\Scope;
use OCA\FolderRetention\Service\DeletionInfo;
use OCA\FolderRetention\Service\Evaluator;
use OCA\FolderRetention\Service\FileCacheReader;
use OCA\FolderRetention\Service\NodeRoots;
use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\RuleResolver;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Folder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

/**
 * Tree: team folder root 1 → "Scans" 20 (1 week, inherit) → file 21; root 1 → file 11 (default 1 month);
 * home root 100 → file 101.
 */
class DeletionInfoTest extends TestCase {
	private const NOW = 1_800_000_000;
	private const UPLOAD = 1_799_000_000;

	private RetentionRoot $team;
	private RetentionRoot $home;
	/** @var array<int, FileRow> */
	private array $rows;
	/** @var array<int, RetentionRoot|null> node id → area */
	private array $areas;
	/** @var array<int, Node> node id → node in the owner's view (default: the node itself) */
	private array $owners = [];
	private bool $simulation = false;
	/** @var array<string, IMountPoint> */
	private array $mounts = [];
	private int $areaLookups = 0;

	protected function setUp(): void {
		$this->team = new RetentionRoot(RetentionRoot::KIND_TEAM, 7, 1, '', 'Team', []);
		$this->home = new RetentionRoot(RetentionRoot::KIND_HOME, 8, 100, 'files', '', ['alice']);
		$this->rows = [
			11 => new FileRow(11, 7, 1, 'a.txt', self::UPLOAD, null, self::UPLOAD),
			20 => new FileRow(20, 7, 1, 'Scans', self::UPLOAD, null, null, isFolder: true),
			21 => new FileRow(21, 7, 20, 'Scans/b.pdf', self::UPLOAD, null, self::UPLOAD),
			101 => new FileRow(101, 8, 100, 'files/c.txt', self::UPLOAD, null, self::UPLOAD),
		];
		$this->areas = [11 => $this->team, 20 => $this->team, 21 => $this->team, 101 => $this->home, 500 => null];
	}

	private function service(): DeletionInfo {
		$roots = $this->createMock(NodeRoots::class);
		$roots->method('ownerView')->willReturnCallback(fn (Node $n) => $this->owners[$n->getId()] ?? $n);
		$roots->method('rootFor')->willReturnCallback(function (Node $n) {
			$this->areaLookups++;
			return $this->areas[$n->getId()] ?? null;
		});

		$cache = $this->createMock(FileCacheReader::class);
		$cache->method('getFileRows')->willReturnCallback(fn (array $ids) => array_intersect_key($this->rows, array_flip($ids)));
		$parents = [1 => -1, 20 => 1, 100 => -1];
		$cache->method('chain')->willReturnCallback(function (int $id, int $stop) use ($parents) {
			$chain = [$id];
			while ($id !== $stop) {
				$id = $parents[$id] ?? -1;
				if ($id < 0) {
					return null;
				}
				$chain[] = $id;
			}
			return $chain;
		});
		$cache->method('getNames')->willReturnCallback(fn (array $ids) => array_intersect_key([20 => 'Scans'], array_flip($ids)));

		$rules = $this->createMock(RuleService::class);
		$rules->method('snapshot')->willReturn(new RuleSet(
			new RetentionRule(1, null, Period::of(1, PeriodUnit::Month), null),
			[20 => new RetentionRule(2, 20, Period::of(1, PeriodUnit::Week), Scope::Inherit, Basis::Created)],
		));
		$runner = $this->createMock(RetentionRunner::class);
		$runner->method('withHistory')->willReturnArgument(0);
		$settings = $this->createMock(Settings::class);
		$settings->method('timezone')->willReturn(new DateTimeZone('UTC'));
		$settings->method('isSimulation')->willReturnCallback(fn () => $this->simulation);
		$settings->method('backgroundJobsMode')->willReturn('cron');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new DeletionInfo($roots, $cache, $rules, new RuleResolver(), new Evaluator(new RuleResolver()), $runner, $settings, $time);
	}

	/** @param string $mount nodes with the same mount share their area lookup */
	private function node(int $id, bool $folder = false, ?string $mount = null): Node {
		$mount ??= (string)$id;
		$this->mounts[$mount] ??= $this->createMock(IMountPoint::class);
		$node = $this->createMock($folder ? Folder::class : File::class);
		$node->method('getId')->willReturn($id);
		$node->method('getMountPoint')->willReturn($this->mounts[$mount]);
		return $node;
	}

	public function testFileInFolderWithRule(): void {
		$this->simulation = true;
		$info = $this->service()->forNodes([$this->node(21)])[21];
		$this->assertSame('file', $info['type']);
		$this->assertSame(self::UPLOAD + 7 * 86400, $info['expiresAt']);
		$this->assertSame(['value' => 1, 'unit' => 'week'], $info['period']);
		$this->assertSame(['kind' => 'folder', 'name' => 'Scans'], $info['source']);
		$this->assertSame(self::UPLOAD, $info['referenceDate']);
		$this->assertSame('upload', $info['referenceSource']);
		$this->assertTrue($info['simulation']);
		$this->assertFalse($info['halted']);
	}

	public function testFolderCarriesTheRuleForItsFiles(): void {
		$result = $this->service()->forNodes([$this->node(20, true, 'team'), $this->node(11, false, 'team')]);
		$this->assertSame(1, $this->areaLookups, 'one area lookup per mount');
		$this->assertSame('folder', $result[20]['type']);
		$this->assertNull($result[20]['expiresAt']);
		$this->assertSame(['kind' => 'own', 'name' => null], $result[20]['source']);
		$this->assertSame(['kind' => 'default', 'name' => null], $result[11]['source']);
		$this->assertSame('month', $result[11]['period']['unit']);
	}

	public function testThroughAShareTheOwnersFolderNameStaysHidden(): void {
		$this->owners[21] = $this->node(21);
		$info = $this->service()->forNodes([$this->node(21)])[21];
		$this->assertSame(['kind' => 'folder', 'name' => null], $info['source']);
		$this->assertSame(self::UPLOAD + 7 * 86400, $info['expiresAt']);
	}

	public function testPersonalFolderWithoutPersonalRule(): void {
		$info = $this->service()->forNodes([$this->node(101)])[101];
		$this->assertSame('personal_default', $info['skip']);
		$this->assertNull($info['expiresAt']);
	}

	public function testUnmanagedNode(): void {
		$this->assertSame([500 => null], $this->service()->forNodes([$this->node(500)]));
	}
}
