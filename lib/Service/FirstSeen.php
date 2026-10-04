<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Writes "first seen" (folder_retention_seen). Reading happens via a join in FileCacheReader.
 */
class FirstSeen {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Boundary between existing and new files, derived from the timestamp of the early 0.8.0 state (seen_since): the smaller of
	 * two boundaries, both conservative –
	 * - highest ID seen up to then: everything created afterwards has a higher ID (including a
	 *   copy that had not even been seen yet at the update);
	 * - just below the smallest ID that was only seen afterwards: a copy created during the
	 *   first cycle in an area that had already been scanned has a smaller ID than files
	 *   from areas scanned later – it counted as new under 0.8.0 and must stay that way.
	 * Existing files above the boundary count from when they were first seen, so they can only become due later, never
	 * earlier.
	 *
	 * @return int|null null = table empty, nothing to derive
	 */
	public function lastIdSeenUntil(int $ts): ?int {
		$max = $this->aggregate('max', $ts, 'lte');
		$min = $this->aggregate('min', $ts, 'gt');
		if ($max === null && $min === null) {
			return null;
		}
		return min($max ?? PHP_INT_MAX, $min === null ? PHP_INT_MAX : max(0, $min - 1));
	}

	private function aggregate(string $fn, int $ts, string $cmp): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->$fn('file_id'))
			->from('folder_retention_seen')
			->where($qb->expr()->$cmp('first_seen', $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false || $value === null ? null : (int)$value;
	}

	/**
	 * Records $now for files without an entry – batched in one transaction. Already existing
	 * entries (parallel run, race) remain unchanged: the earlier date applies.
	 *
	 * @param list<int> $fileIds
	 */
	public function record(array $fileIds, int $now): void {
		if ($fileIds === []) {
			return;
		}
		$this->db->beginTransaction();
		try {
			foreach (array_unique($fileIds) as $id) {
				$this->db->insertIgnoreConflict('folder_retention_seen', ['file_id' => $id, 'first_seen' => $now]);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Restored from the trash bin: set "first seen" to $now, even if an entry from before the
	 * deletion already exists – the retention period counts from here (ReferenceDate). A later
	 * date than that of a parallel run only pushes the due date back.
	 *
	 * @param list<int> $fileIds
	 */
	public function recordRestored(array $fileIds, int $now): void {
		if ($fileIds === []) {
			return;
		}
		$this->db->beginTransaction();
		try {
			foreach (array_unique($fileIds) as $id) {
				$qb = $this->db->getQueryBuilder();
				$qb->update('folder_retention_seen')
					->set('first_seen', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
					->where($qb->expr()->eq('file_id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
				if ($qb->executeStatement() === 0) {
					$this->db->insertIgnoreConflict('folder_retention_seen', ['file_id' => $id, 'first_seen' => $now]);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
}
