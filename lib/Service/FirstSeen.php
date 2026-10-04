<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Schreibt „zuerst gesehen“ (folder_retention_seen). Gelesen wird per Join in FileCacheReader.
 */
class FirstSeen {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Grenze Bestand/neu aus dem Zeitpunkt des frühen 0.8.0-Stands (seen_since): die kleinere von
	 * zwei Grenzen, beide vorsichtig –
	 * - höchste bis dahin gesehene ID: Alles, was danach entstand, hat eine höhere ID (auch eine
	 *   Kopie, die beim Update noch gar nicht gesehen war);
	 * - knapp unter der kleinsten ID, die erst danach gesehen wurde: Eine Kopie, die während des
	 *   ersten Zyklus in einem schon gescannten Bereich entstand, hat eine kleinere ID als Dateien
	 *   aus später gescannten Bereichen – sie galt unter 0.8.0 als neu und muss es bleiben.
	 * Bestand oberhalb der Grenze zählt ab dem ersten Sehen, wird also höchstens später fällig, nie
	 * früher.
	 *
	 * @return int|null null = Tabelle leer, nichts abzuleiten
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
	 * Vermerkt $now für Dateien ohne Eintrag – gebündelt in einer Transaktion. Schon vorhandene
	 * Einträge (paralleler Lauf, Wettlauf) bleiben unverändert: das frühere Datum gilt.
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
	 * Aus dem Papierkorb zurückgeholt: „zuerst gesehen“ auf $now setzen, auch wenn schon ein
	 * Eintrag von vor der Löschung besteht – ab hier zählt die Frist (ReferenceDate). Ein späteres
	 * Datum als das eines parallelen Laufs schiebt die Fälligkeit nur nach hinten.
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
