<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use DateTimeImmutable;
use OCA\FolderRetention\Db\LogEntry;
use OCA\FolderRetention\Db\LogMapper;

/**
 * Log overview: entries per calendar day (instance time zone, like the CSV export)
 * and within that per folder. A day usually corresponds to one nightly run. Counting happens in
 * PHP over the narrow columns – "day" depends on the time zone including daylight saving time, and
 * SQL cannot derive the parent folder of a path portably.
 *
 * @psalm-import-type LogFilter from LogMapper
 * @psalm-type Counts = array{deleted: int, would_delete: int, superseded: int, skipped: int, error: int}
 */
class LogSummary {
	/** Status groups like the log's status filter; categoryOf() in src/format.js mirrors this */
	public const CATEGORIES = ['deleted', 'would_delete', 'superseded', 'skipped', 'error'];

	public function __construct(
		private LogMapper $mapper,
		private Settings $settings,
	) {
	}

	/**
	 * Days newest first; total = number of all days with entries. Reads all matching
	 * rows for this (narrow, in chunks) – for a very large log, move the counting into SQL.
	 *
	 * @param LogFilter $filter
	 * @return array{days: list<array{date: string, from: int, to: int, total: int, counts: Counts}>, total: int}
	 */
	public function days(array $filter, int $limit, int $offset): array {
		$tz = $this->settings->timezone();
		$days = [];
		foreach ($this->mapper->iterateSummary($filter) as $row) {
			$date = (new DateTimeImmutable('@' . $row['deleted_at']))->setTimezone($tz)->format('Y-m-d');
			$days[$date] ??= ['total' => 0, 'counts' => self::emptyCounts()];
			$days[$date]['total']++;
			$days[$date]['counts'][self::category($row['status'], $row['superseded'])]++;
		}
		krsort($days, SORT_STRING);
		$out = [];
		foreach (array_slice($days, $offset, $limit, true) as $date => $day) {
			$start = new DateTimeImmutable($date . ' 00:00:00', $tz);
			$out[] = [
				'date' => (string)$date,
				'from' => $start->getTimestamp(),
				'to' => $start->modify('+1 day')->getTimestamp() - 1,
				'total' => $day['total'],
				'counts' => $day['counts'],
			];
		}
		return ['days' => $out, 'total' => count($days)];
	}

	/**
	 * @param LogFilter $filter usually with from/to of one day
	 * One group per area key and parent folder: two areas with the same name (or a renamed one)
	 * stay apart. root = RetentionRoot::blockKey(), '' for older entries without a key.
	 * Superseded "would delete" entries form groups of their own (superseded = true) after all
	 * others – they no longer apply and should not mix with what a run did or would do.
	 *
	 * @return list<array{folder: string, root: string, superseded: bool, total: int, counts: Counts}> sorted by folder path
	 */
	public function folders(array $filter): array {
		$folders = [];
		foreach ($this->mapper->iterateSummary($filter) as $row) {
			$folder = self::folderOf($row['path']);
			$root = $row['root_key'] ?? '';
			$key = ($row['superseded'] ? '1' : '0') . "\n" . $root . "\n" . $folder;
			$folders[$key] ??= ['folder' => $folder, 'root' => $root, 'superseded' => $row['superseded'], 'total' => 0, 'counts' => self::emptyCounts()];
			$folders[$key]['total']++;
			$folders[$key]['counts'][self::category($row['status'], $row['superseded'])]++;
		}
		usort($folders, fn (array $a, array $b) => $a['superseded'] <=> $b['superseded']
			?: strnatcasecmp($a['folder'], $b['folder'])
			?: strcmp($a['root'], $b['root']));
		return array_values($folders);
	}

	/** Direct parent folder of a log path; '' for a path without a folder */
	public static function folderOf(string $path): string {
		$pos = strrpos($path, '/');
		return $pos === false ? '' : substr($path, 0, $pos);
	}

	/** Groups like the log's status filter; $superseded only matters for "would delete" */
	public static function category(string $status, bool $superseded = false): string {
		return match (true) {
			$status === LogEntry::STATUS_DELETED => 'deleted',
			$status === LogEntry::STATUS_WOULD_DELETE => $superseded ? 'superseded' : 'would_delete',
			str_starts_with($status, 'skipped') => 'skipped',
			default => 'error',
		};
	}

	/** @return Counts */
	private static function emptyCounts(): array {
		/** @var Counts */
		return array_fill_keys(self::CATEGORIES, 0);
	}
}
