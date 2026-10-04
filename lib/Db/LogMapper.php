<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<LogEntry>
 *
 * Filter (alle optional): mode (real|simulation), status (deleted|would_delete|skipped|error –
 * „error“ schließt endgültige Löschungen ein),
 * search (Teil des Pfads), from/to (Unix-Zeitstempel, einschließlich).
 * @psalm-type LogFilter = array{mode?: ?string, status?: ?string, search?: ?string, from?: ?int, to?: ?int}
 */
class LogMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'folder_retention_log', LogEntry::class);
	}

	/**
	 * @param LogFilter $filter
	 * @return list<LogEntry> neueste zuerst
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
	 * Alle passenden Einträge in Blöcken, neueste zuerst (für den CSV-Export).
	 *
	 * @param LogFilter $filter
	 * @return \Generator<LogEntry>
	 */
	public function iterate(array $filter = [], int $chunk = 1000): \Generator {
		$beforeId = null;
		while (true) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($this->getTableName())
				->orderBy('id', 'DESC')
				->setMaxResults($chunk);
			$this->applyFilter($qb, $filter);
			if ($beforeId !== null) {
				$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter($beforeId, IQueryBuilder::PARAM_INT)));
			}
			$entries = $this->findEntities($qb);
			foreach ($entries as $entry) {
				yield $entry;
			}
			if (count($entries) < $chunk) {
				return;
			}
			$beforeId = end($entries)->getId();
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
	}

	/**
	 * Letzte echte Löschung je Datei-ID (mode real, status deleted). Wird eine Datei aus dem
	 * Papierkorb wiederhergestellt, behält sie ihre ID – ab diesem Zeitpunkt zählt die Frist neu.
	 *
	 * @param list<int> $fileIds
	 * @return array<int, int> fileid → Zeitpunkt
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
	 * Nachträglich als endgültig gelöscht kennzeichnen: Die Datei galt als in den Papierkorb
	 * verschoben (status deleted), ihr Papierkorb-Eintrag ist aber verloren gegangen. Betrifft
	 * den neuesten echten „deleted“-Eintrag dieser Datei-ID.
	 *
	 * @return bool false = kein solcher Eintrag (z. B. Protokollieren war fehlgeschlagen)
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
	 * Im Simulationsmodus wird eine Datei nur einmal pro Bewertung geloggt, sonst füllt der
	 * tägliche Lauf das Log mit Wiederholungen. Der erste Eintrag zeigt, seit wann sie fällig ist.
	 * Bewertung = Regel samt Frist (rule_label nennt sie) und Bezugsdatum samt Quelle: Ändert sich
	 * eines davon (Friständerung, Update von 0.7.x mit altem Bezugsdatum, neue Upload-Zeit), kommt
	 * ein neuer Eintrag – sonst stünde im Log weiter ein veraltetes „würde löschen“.
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
