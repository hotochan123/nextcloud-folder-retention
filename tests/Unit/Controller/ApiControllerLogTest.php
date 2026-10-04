<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Controller;

use OCA\FolderRetention\Controller\ApiController;
use OCA\FolderRetention\Db\LogMapper;
use OCA\FolderRetention\Service\LogSummary;
use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\RootProvider;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCA\FolderRetention\Service\TagService;
use OCA\FolderRetention\Service\TreeService;
use OCA\FolderRetention\Tests\Unit\FakeL10N;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Log endpoints: bounds for limit/offset/time range and which filters reach the mapper.
 */
class ApiControllerLogTest extends TestCase {
	private LogMapper&MockObject $mapper;
	private LogSummary&MockObject $summary;

	private function controller(): ApiController {
		$this->mapper = $this->createMock(LogMapper::class);
		$this->summary = $this->createMock(LogSummary::class);
		return new ApiController(
			$this->createMock(IRequest::class),
			$this->createMock(RuleService::class),
			$this->createMock(TreeService::class),
			$this->createMock(RootProvider::class),
			$this->createMock(RetentionRunner::class),
			$this->mapper,
			$this->summary,
			$this->createMock(Settings::class),
			$this->createMock(IUserSession::class),
			$this->createMock(TagService::class),
			$this->createMock(IJobList::class),
			$this->createMock(IUserManager::class),
			FakeL10N::de(),
		);
	}

	public function testLogPassesFolderOnlyWhenGiven(): void {
		$c = $this->controller();
		$seen = [];
		$this->mapper->method('findPage')->willReturnCallback(function (int $limit, int $offset, array $filter) use (&$seen) {
			$seen[] = [$limit, $offset, $filter];
			return [];
		});
		$c->log(9999, -5);
		$c->log(50, 0, null, null, null, null, null, '');
		$c->log(50, 0, 'bogus', 'deleted_final', '  ', -1, 0, str_repeat('ä', 5000));

		$this->assertSame(500, $seen[0][0], 'limit capped');
		$this->assertSame(0, $seen[0][1], 'negative offset becomes 0');
		$this->assertArrayNotHasKey('folder', $seen[0][2], 'no folder parameter = all folders');
		$this->assertSame('', $seen[1][2]['folder'], 'folder= means paths without a folder');
		$this->assertSame(['mode' => null, 'status' => null, 'search' => null, 'from' => null, 'to' => null], array_diff_key($seen[2][2], ['folder' => 1]), 'unknown values are dropped, not passed through');
		$this->assertSame(4000, mb_strlen($seen[2][2]['folder']));
	}

	public function testDaysBoundsLimit(): void {
		$c = $this->controller();
		$calls = [];
		$this->summary->method('days')->willReturnCallback(function (array $filter, int $limit, int $offset) use (&$calls) {
			$calls[] = [$limit, $offset, $filter];
			return ['days' => [], 'total' => 0];
		});
		$c->logDays(0, -1);
		$c->logDays(1000, 3, null, 'skipped', 'x', 10, 20);
		$this->assertSame([1, 0], array_slice($calls[0], 0, 2));
		$this->assertSame([100, 3], array_slice($calls[1], 0, 2));
		$this->assertSame(['mode' => null, 'status' => 'skipped', 'search' => 'x', 'from' => 10, 'to' => 20], $calls[1][2]);
	}

	public function testFoldersNeedABoundedTimeRange(): void {
		$c = $this->controller();
		$this->summary->expects($this->once())->method('folders')
			->with(['mode' => null, 'status' => null, 'search' => null, 'from' => 1000, 'to' => 1000 + 86399])
			->willReturn([]);
		foreach ([[null, null], [1000, null], [null, 1000], [2000, 1000], [0, 10], [1000, 1000 + 32 * 86400]] as [$from, $to]) {
			$r = $c->logFolders(null, null, null, $from, $to);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus(), "from=$from to=$to");
		}
		$this->assertSame(Http::STATUS_OK, $c->logFolders(null, null, null, 1000, 1000 + 86399)->getStatus());
	}
}
