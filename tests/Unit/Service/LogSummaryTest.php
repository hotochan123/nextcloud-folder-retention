<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\FolderRetention\Db\LogMapper;
use OCA\FolderRetention\Service\LogSummary;
use OCA\FolderRetention\Service\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Per-day and per-folder summary of the log: days in the instance time zone, folder = direct
 * parent folder, status grouped as in the status filter.
 */
class LogSummaryTest extends TestCase {
	/** @var list<array{path: string, status: string, deleted_at: int}> newest first, like iterateSummary() */
	private array $rows = [];
	/** @var list<array<string, mixed>> */
	private array $filters = [];

	private function summary(string $tz = 'Europe/Berlin'): LogSummary {
		$mapper = $this->createMock(LogMapper::class);
		$mapper->method('iterateSummary')->willReturnCallback(function (array $filter) {
			$this->filters[] = $filter;
			yield from $this->rows;
		});
		$settings = $this->createMock(Settings::class);
		$settings->method('timezone')->willReturn(new DateTimeZone($tz));
		return new LogSummary($mapper, $settings);
	}

	private function row(string $path, string $status, string $at, string $tz = 'Europe/Berlin'): array {
		return ['path' => $path, 'status' => $status, 'deleted_at' => (new DateTimeImmutable($at, new DateTimeZone($tz)))->getTimestamp()];
	}

	public function testDaysAreCountedInInstanceTimezoneNewestFirst(): void {
		$this->rows = [
			$this->row('A/x.pdf', 'would_delete', '2026-10-04 02:06'),
			$this->row('A/y.pdf', 'skipped_locked', '2026-10-04 00:30'),
			// 23:30 UTC on 2.10. is already 3.10. in Berlin
			$this->row('B/z.pdf', 'deleted', '2026-10-02 23:30', 'UTC'),
			$this->row('B/w.pdf', 'deleted_final', '2026-10-02 22:00', 'UTC'),
		];
		$result = $this->summary()->days([], 10, 0);
		$this->assertSame(2, $result['total']);
		$this->assertSame(['2026-10-04', '2026-10-03'], array_column($result['days'], 'date'));
		$this->assertSame(2, $result['days'][0]['total']);
		$this->assertSame(['deleted' => 0, 'would_delete' => 1, 'skipped' => 1, 'error' => 0], $result['days'][0]['counts']);
		$this->assertSame(['deleted' => 1, 'would_delete' => 0, 'skipped' => 0, 'error' => 1], $result['days'][1]['counts']);
	}

	public function testDayBoundsCoverTheLocalDayIncludingDaylightSavingChange(): void {
		// 25.10.2026: end of daylight saving time in Berlin, the day has 25 hours
		$this->rows = [$this->row('A/x.pdf', 'would_delete', '2026-10-25 12:00')];
		$day = $this->summary()->days([], 10, 0)['days'][0];
		$this->assertSame((new DateTimeImmutable('2026-10-25 00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp(), $day['from']);
		$this->assertSame(25 * 3600 - 1, $day['to'] - $day['from']);
	}

	public function testDaysArePaged(): void {
		foreach (['2026-10-04', '2026-10-03', '2026-10-02', '2026-10-01'] as $d) {
			$this->rows[] = $this->row('A/x.pdf', 'would_delete', $d . ' 02:00');
		}
		$page = $this->summary()->days(['search' => 'x'], 2, 2);
		$this->assertSame(4, $page['total']);
		$this->assertSame(['2026-10-02', '2026-10-01'], array_column($page['days'], 'date'));
		$this->assertSame([['search' => 'x']], $this->filters, 'the filter reaches the mapper unchanged');
	}

	public function testFoldersAreDirectParentsSortedNaturally(): void {
		$this->rows = [
			$this->row('Team/Folder 10/a.pdf', 'would_delete', '2026-10-04 02:00'),
			$this->row('Team/Folder 2/b.pdf', 'would_delete', '2026-10-04 02:00'),
			$this->row('Team/Folder 2/sub/c.pdf', 'error', '2026-10-04 02:00'),
			$this->row('Team/Folder 2/d.pdf', 'skipped_changed', '2026-10-04 02:00'),
			$this->row('loose.txt', 'deleted', '2026-10-04 02:00'),
		];
		$folders = $this->summary()->folders(['from' => 1, 'to' => 2]);
		$this->assertSame(['', 'Team/Folder 2', 'Team/Folder 2/sub', 'Team/Folder 10'], array_column($folders, 'folder'));
		$this->assertSame(2, $folders[1]['total']);
		$this->assertSame(['deleted' => 0, 'would_delete' => 1, 'skipped' => 1, 'error' => 0], $folders[1]['counts']);
		$this->assertSame(1, $folders[2]['counts']['error']);
		$this->assertSame([['from' => 1, 'to' => 2]], $this->filters);
	}

	public function testSameNamedAreasStayApart(): void {
		// two Team folders both mounted as "Archive", plus an older entry without a key
		$this->rows = [
			['root_key' => '0000000001:000000000100'] + $this->row('Archive/a.pdf', 'would_delete', '2026-10-04 02:00'),
			['root_key' => '0000000001:000000000200'] + $this->row('Archive/b.pdf', 'would_delete', '2026-10-04 02:00'),
			['root_key' => '0000000001:000000000200'] + $this->row('Archive/c.pdf', 'error', '2026-10-04 02:00'),
			['root_key' => null] + $this->row('Archive/d.pdf', 'deleted', '2026-10-04 02:00'),
		];
		$folders = $this->summary()->folders(['from' => 1, 'to' => 2]);
		$this->assertSame(['', '0000000001:000000000100', '0000000001:000000000200'], array_column($folders, 'root'));
		$this->assertSame(['Archive', 'Archive', 'Archive'], array_column($folders, 'folder'));
		$this->assertSame([1, 1, 2], array_column($folders, 'total'));
	}

	public function testFolderOfAndCategory(): void {
		$this->assertSame('a/b', LogSummary::folderOf('a/b/c.txt'));
		$this->assertSame('', LogSummary::folderOf('c.txt'));
		$this->assertSame('skipped', LogSummary::category('skipped_blocked'));
		$this->assertSame('error', LogSummary::category('deleted_final'));
		$this->assertSame('error', LogSummary::category('something_new'), 'unknown states stay visible as errors');
	}
}
