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
 * @psalm-type Counts = array{deleted: int, would_delete: int, skipped: int, error: int}
 */
class LogSummary {
	/** Status groups like the log's status filter; categoryOf() in src/format.js mirrors this */
	public const CATEGORIES = ['deleted', 'would_delete', 'skipped', 'error'];

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
			$days[$date]['counts'][self::category($row['status'])]++;
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
	 * @return list<array{folder: string, total: int, counts: Counts}> sorted by folder path
	 */
	public function folders(array $filter): array {
		$folders = [];
		foreach ($this->mapper->iterateSummary($filter) as $row) {
			$folder = self::folderOf($row['path']);
			$folders[$folder] ??= ['folder' => $folder, 'total' => 0, 'counts' => self::emptyCounts()];
			$folders[$folder]['total']++;
			$folders[$folder]['counts'][self::category($row['status'])]++;
		}
		uksort($folders, fn ($a, $b) => strnatcasecmp((string)$a, (string)$b));
		return array_values($folders);
	}

	/** Direct parent folder of a log path; '' for a path without a folder */
	public static function folderOf(string $path): string {
		$pos = strrpos($path, '/');
		return $pos === false ? '' : substr($path, 0, $pos);
	}

	/** Groups like the log's status filter */
	public static function category(string $status): string {
		return match (true) {
			$status === LogEntry::STATUS_DELETED => 'deleted',
			$status === LogEntry::STATUS_WOULD_DELETE => 'would_delete',
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
