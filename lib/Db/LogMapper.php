<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<LogEntry>
 *
 * Filters (all optional): mode (real|simulation – API and CSV only, the UI does not filter by it), status (deleted|would_delete|skipped|error –
 * "error" includes permanent deletions),
 * search (part of the path), from/to (Unix timestamps, inclusive),
 * folder (exactly this parent folder of the path, without subfolders; '' = paths without a folder).
 * @psalm-type LogFilter = array{mode?: ?string, status?: ?string, search?: ?string, from?: ?int, to?: ?int, folder?: ?string}
 */
class LogMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'folder_retention_log', LogEntry::class);
	}

	/**
	 * @param LogFilter $filter
	 * @return list<LogEntry> newest first
	 */
	public function findPage(int $limit, int $offset, array $filter = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		$this->applyFilter($qb, $filter);
		return $this->findEntities($qb);
	}

	/** @param LogFilter $filter */
	public function count(array $filter = []): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName());
		$this->applyFilter($qb, $filter);
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/**
	 * All matching entries in chunks, newest first (for the CSV export).
	 *
	 * @param LogFilter $filter
	 * @return \Generator<LogEntry>
	 */
	public function iterate(array $filter = [], int $chunk = 1000): \Generator {
		foreach ($this->chunks(['*'], $filter, $chunk) as $row) {
			yield $this->mapRowToEntity($row);
		}
	}

	/**
	 * Only the columns for the per-day and per-folder overview, in chunks, newest first.
	 *
	 * @param LogFilter $filter
	 * @return \Generator<array{path: string, status: string, deleted_at: int}>
	 */
	public function iterateSummary(array $filter = [], int $chunk = 5000): \Generator {
		foreach ($this->chunks(['id', 'path', 'status', 'deleted_at'], $filter, $chunk) as $row) {
			yield ['path' => (string)$row['path'], 'status' => (string)$row['status'], 'deleted_at' => (int)$row['deleted_at']];
		}
	}

	/**
	 * Rows in chunks by id descending – continuing from the smallest id of the previous chunk instead
	 * of using an offset, so that new entries written while reading don't shift anything.
	 *
	 * @param list<string> $columns must include id
	 * @param LogFilter $filter
	 * @return \Generator<array<string, mixed>>
	 */
	private function chunks(array $columns, array $filter, int $chunk): \Generator {
		$beforeId = null;
		while (true) {
			$qb = $this->db->getQueryBuilder();
			$qb->select(...$columns)->from($this->getTableName())
				->orderBy('id', 'DESC')
				->setMaxResults($chunk);
			$this->applyFilter($qb, $filter);
			if ($beforeId !== null) {
				$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
			}
			$result = $qb->executeQuery();
			$n = 0;
			while ($row = $result->fetch()) {
				$n++;
				$beforeId = (int)$row['id'];
				yield $row;
			}
			$result->closeCursor();
			if ($n < $chunk) {
				return;
			}
		}
	}

	/** @param LogFilter $filter */
	private function applyFilter(IQueryBuilder $qb, array $filter): void {
		$qb->where('1 = 1');
		if (!empty($filter['mode'])) {
			$qb->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter($filter['mode'])));
		}
		if (!empty($filter['status'])) {
			if ($filter['status'] === 'skipped') {
				$qb->andWhere($qb->expr()->like('status', $qb->createNamedParameter('skipped%')));
			} elseif ($filter['status'] === LogEntry::STATUS_ERROR) {
				$qb->andWhere($qb->expr()->in('status', $qb->createNamedParameter([LogEntry::STATUS_ERROR, LogEntry::STATUS_DELETED_FINAL], IQueryBuilder::PARAM_STR_ARRAY)));
			} else {
				$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($filter['status'])));
			}
		}
		if (!empty($filter['search'])) {
			$qb->andWhere($qb->expr()->iLike('path', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($filter['search']) . '%')));
		}
		if (!empty($filter['from'])) {
			$qb->andWhere($qb->expr()->gte('deleted_at', $qb->createNamedParameter($filter['from'], IQueryBuilder::PARAM_INT)));
		}
		if (!empty($filter['to'])) {
			$qb->andWhere($qb->expr()->lte('deleted_at', $qb->createNamedParameter($filter['to'], IQueryBuilder::PARAM_INT)));
		}
		// Same boundary as LogSummary::folderOf(): direct parent folder, no subfolders.
		// NOT (… LIKE …) instead of notLike(): notLike() appends no ESCAPE on SQLite and Oracle,
		// so a folder with "%" or "_" in its name would otherwise also show its subfolders.
		// Known edge case: MySQL/MariaDB compare case-insensitively with a *_ci collation,
		// so "Docs" there also shows the files from "docs", which the overview counts separately.
		if (isset($filter['folder'])) {
			$prefix = $filter['folder'] === '' ? '' : $this->db->escapeLikeParameter($filter['folder']) . '/';
			if ($prefix !== '') {
				$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter($prefix . '%')));
			}
			$qb->andWhere('NOT (' . $qb->expr()->like('path', $qb->createNamedParameter($prefix . '%/%')) . ')');
		}
	}

	/**
	 * Last real deletion per file ID (mode real, status deleted). When a file is restored from the
	 * trash bin it keeps its ID – from that point on the retention period starts over.
	 *
	 * @param list<int> $fileIds
	 * @return array<int, int> fileid → timestamp
	 */
	public function lastDeleted(array $fileIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($fileIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id')->selectAlias($qb->func()->max('deleted_at'), 'last')
				->from($this->getTableName())
				->where($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter(LogEntry::MODE_REAL)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(LogEntry::STATUS_DELETED)))
				->groupBy('file_id');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$out[(int)$row['file_id']] = (int)$row['last'];
			}
			$result->closeCursor();
		}
		return $out;
	}

	/**
	 * The newest entry of this file ID, if it reports exactly the same again (mode, status, rule,
	 * message) – e.g. the same error every night. The caller then moves its time forward instead
	 * of adding a row per run.
	 *
	 * @return int|null id of that entry
	 */
	public function findRepeat(int $fileId, string $mode, string $status, ?string $ruleLabel, ?string $message): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'mode', 'status', 'rule_label', 'message')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false
			|| $row['mode'] !== $mode
			|| $row['status'] !== $status
			|| (string)$row['rule_label'] !== (string)$ruleLabel
			|| (string)$row['message'] !== (string)$message) {
			return null;
		}
		return (int)$row['id'];
	}

	/** Moves an entry to a new time (repeated report, see findRepeat) */
	public function touch(int $id, int $at): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('deleted_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Deletes entries older than $cutoff. Kept regardless of age: real deletions ("deleted") whose
	 * file still exists in the file cache – in the trash bin or restored from it. lastDeleted()
	 * needs them: after a restore the retention period counts from the restore, without the entry
	 * the file would be due again immediately. Once the file is gone for good, they go too.
	 *
	 * @return int number of deleted entries
	 */
	public function purgeOlderThan(int $cutoff, int $chunk = 1000): int {
		$purged = 0;
		$afterId = 0;
		while (true) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'file_id', 'mode', 'status')->from($this->getTableName())
				->where($qb->expr()->lt('deleted_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
				->orderBy('id', 'ASC')
				->setMaxResults($chunk);
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
			if ($rows === []) {
				return $purged;
			}
			$afterId = (int)$rows[count($rows) - 1]['id'];

			$isRealDeletion = static fn (array $row): bool => $row['mode'] === LogEntry::MODE_REAL && $row['status'] === LogEntry::STATUS_DELETED;
			$existing = $this->existingFileIds(array_map(
				static fn (array $row): int => (int)$row['file_id'],
				array_filter($rows, $isRealDeletion),
			));
			$ids = [];
			foreach ($rows as $row) {
				if (!($isRealDeletion($row) && isset($existing[(int)$row['file_id']]))) {
					$ids[] = (int)$row['id'];
				}
			}
			if ($ids !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($this->getTableName())
					->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
				$purged += $qb->executeStatement();
			}
			if (count($rows) < $chunk) {
				return $purged;
			}
		}
	}

	/**
	 * All entries of one storage – the home storage of a deleted account.
	 *
	 * @return int number of deleted entries
	 */
	public function deleteByStorage(int $storageId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('storage_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * @param array<int> $fileIds
	 * @return array<int, true> those of $fileIds that are in the file cache
	 */
	private function existingFileIds(array $fileIds): array {
		$out = [];
		foreach (array_chunk(array_values(array_unique($fileIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid')->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while (($id = $result->fetchOne()) !== false) {
				$out[(int)$id] = true;
			}
			$result->closeCursor();
		}
		return $out;
	}

	/**
	 * Retroactively mark as permanently deleted: the file was considered moved to the trash bin
	 * (status deleted), but its trash bin entry has been lost. Applies to
	 * the newest real "deleted" entry for this file ID.
	 *
	 * @return bool false = no such entry (e.g. logging had failed)
	 */
	public function markDeletedFinal(int $fileId, string $message): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter(LogEntry::MODE_REAL)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(LogEntry::STATUS_DELETED)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		if ($id === false) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(LogEntry::STATUS_DELETED_FINAL))
			->set('message', $qb->createNamedParameter(mb_substr($message, 0, 1000)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return true;
	}

	/**
	 * In simulation mode a file is logged only once per evaluation, otherwise the daily run
	 * fills the log with repeats. The first entry shows since when it has been due.
	 * Evaluation = rule including its retention period (rule_label names it) and reference date including its source: if
	 * any of these changes (period change, update from 0.7.x with an old reference date, new upload time), a
	 * new entry is written – otherwise the log would keep showing an outdated "would delete".
	 */
	public function hasSimulated(int $fileId, ?int $ruleId, string $ruleLabel, int $referenceDate, string $referenceSource): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter(LogEntry::MODE_SIMULATION)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(LogEntry::STATUS_WOULD_DELETE)))
			->andWhere($qb->expr()->eq('rule_label', $qb->createNamedParameter($ruleLabel)))
			->andWhere($qb->expr()->eq('reference_date', $qb->createNamedParameter($referenceDate, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('reference_source', $qb->createNamedParameter($referenceSource)))
			->setMaxResults(1);
		if ($ruleId === null) {
			$qb->andWhere($qb->expr()->isNull('rule_id'));
		} else {
			$qb->andWhere($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}
}
