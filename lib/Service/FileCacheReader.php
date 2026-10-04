<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Model\FileRow;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;

/**
 * Lesender Direktzugriff auf oc_filecache. Bewusst ohne Dateisystem-Setup pro Benutzer:
 * Der Job bewertet Storages, nicht Benutzersichten.
 */
class FileCacheReader {
	private const MAX_DEPTH = 256;

	private ?int $folderMime = null;
	/** @var array<int, int> fileid → parent, nur Ordner */
	private array $parents = [];

	public function __construct(
		private IDBConnection $db,
		private IMimeTypeLoader $mimeTypeLoader,
	) {
	}

	private function folderMime(): int {
		return $this->folderMime ??= $this->mimeTypeLoader->getId('httpd/unix-directory');
	}

	/**
	 * Nächster Batch Dateien eines Storages unterhalb von $pathPrefix, nach fileid.
	 *
	 * @param string $pathPrefix interner Pfad ohne abschließenden Slash; '' = ganzer Storage
	 * @param bool $includeFolders Ordner mitliefern (FileRow::$isFolder) – nur für den Tag-Abgleich
	 * @return list<FileRow>
	 */
	public function fetchFiles(int $storageId, string $pathPrefix, int $afterFileId, int $limit, bool $includeFolders = false): array {
		$qb = $this->db->getQueryBuilder();
		$this->selectFileRows($qb)
			->where($qb->expr()->eq('fc.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('fc.fileid', $qb->createNamedParameter($afterFileId, IQueryBuilder::PARAM_INT)))
			->orderBy('fc.fileid', 'ASC')
			->setMaxResults($limit);
		if (!$includeFolders) {
			$qb->andWhere($qb->expr()->neq('fc.mimetype', $qb->createNamedParameter($this->folderMime(), IQueryBuilder::PARAM_INT)));
		}
		if ($pathPrefix !== '') {
			$qb->andWhere($qb->expr()->like('fc.path', $qb->createNamedParameter($this->db->escapeLikeParameter($pathPrefix) . '/%')));
		}

		$rows = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$rows[] = $this->toFileRow($row);
		}
		$result->closeCursor();
		return $rows;
	}

	/** Höchste vergebene Datei-ID (0 bei leerem Filecache) – Grenze Bestand/neu, siehe Settings */
	public function maxFileId(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('fileid'))->from('filecache');
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return (int)$max;
	}

	/**
	 * Eine Datei frisch aus der DB (für die Neuprüfung unmittelbar vor dem Löschen).
	 */
	public function getFileRow(int $fileId): ?FileRow {
		$qb = $this->db->getQueryBuilder();
		$this->selectFileRows($qb)
			->where($qb->expr()->eq('fc.fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row === false ? null : $this->toFileRow($row);
	}

	private function selectFileRows(IQueryBuilder $qb): IQueryBuilder {
		return $qb->select('fc.fileid', 'fc.storage', 'fc.parent', 'fc.path', 'fc.mtime', 'fc.size', 'fc.mimetype', 'fe.creation_time', 'fe.upload_time', 'fs.first_seen')
			->from('filecache', 'fc')
			->leftJoin('fc', 'filecache_extended', 'fe', $qb->expr()->eq('fe.fileid', 'fc.fileid'))
			->leftJoin('fc', 'folder_retention_seen', 'fs', $qb->expr()->eq('fs.file_id', 'fc.fileid'));
	}

	/** @param array<string, mixed> $row */
	private function toFileRow(array $row): FileRow {
		return new FileRow(
			(int)$row['fileid'],
			(int)$row['storage'],
			(int)$row['parent'],
			(string)$row['path'],
			(int)$row['mtime'],
			$row['creation_time'] === null ? null : (int)$row['creation_time'],
			$row['upload_time'] === null ? null : (int)$row['upload_time'],
			(int)$row['size'],
			(int)$row['mimetype'] === $this->folderMime(),
			$row['first_seen'] === null ? null : (int)$row['first_seen'],
		);
	}

	/**
	 * Ordnerkette ab $folderId aufwärts bis einschließlich $stopAt.
	 *
	 * @return list<int>|null null, wenn $stopAt nicht erreicht wird (Ordner liegt außerhalb)
	 */
	public function chain(int $folderId, int $stopAt): ?array {
		$chain = [];
		$current = $folderId;
		for ($i = 0; $i < self::MAX_DEPTH; $i++) {
			$chain[] = $current;
			if ($current === $stopAt) {
				return $chain;
			}
			if (!array_key_exists($current, $this->parents)) {
				$this->loadParents([$current]);
			}
			$parent = $this->parents[$current] ?? -1;
			if ($parent < 0) {
				return null;
			}
			$current = $parent;
		}
		return null;
	}

	/**
	 * Lädt Elternbeziehungen für die angegebenen Ordner und alle Vorfahren – ebenenweise gebündelt,
	 * damit ein Batch Dateien nur so viele Queries braucht, wie der Baum tief ist.
	 *
	 * @param list<int> $folderIds
	 */
	public function prefetch(array $folderIds): void {
		$todo = array_values(array_unique(array_filter($folderIds, fn ($id) => !array_key_exists($id, $this->parents))));
		for ($i = 0; $todo !== [] && $i < self::MAX_DEPTH; $i++) {
			$this->loadParents($todo);
			$next = [];
			foreach ($todo as $id) {
				$p = $this->parents[$id] ?? -1;
				if ($p >= 0 && !array_key_exists($p, $this->parents)) {
					$next[$p] = $p;
				}
			}
			$todo = array_values($next);
		}
	}

	/** @param list<int> $ids */
	private function loadParents(array $ids): void {
		foreach (array_chunk($ids, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'parent')->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$this->parents[(int)$row['fileid']] = (int)$row['parent'];
			}
			$result->closeCursor();
		}
		foreach ($ids as $id) {
			// nicht (mehr) vorhanden → als Wurzel behandeln
			$this->parents[$id] ??= -1;
		}
	}

	/**
	 * Wie chain(), aber ohne Zwischenspeicher: jede Ebene frisch aus der DB. Für die Neuprüfung
	 * vor dem Löschen – der Zwischenspeicher eines Scans kann Stunden alt sein.
	 *
	 * @return list<int>|null
	 */
	public function freshChain(int $folderId, int $stopAt): ?array {
		$chain = [];
		$current = $folderId;
		for ($i = 0; $i < self::MAX_DEPTH; $i++) {
			$chain[] = $current;
			if ($current === $stopAt) {
				return $chain;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('parent')->from('filecache')
				->where($qb->expr()->eq('fileid', $qb->createNamedParameter($current, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$parent = $result->fetchOne();
			$result->closeCursor();
			if ($parent === false || (int)$parent < 0) {
				return null;
			}
			$current = (int)$parent;
		}
		return null;
	}

	/**
	 * Steht die Datei-ID im Papierkorb der Team-Ordner (oc_group_folders_trash.file_id)?
	 *
	 * @return bool|null null = nicht feststellbar (groupfolders fehlt oder ältere Tabelle ohne file_id)
	 */
	public function isInGroupFolderTrash(int $fileId): ?bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('file_id')->from('group_folders_trash')
				->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$found = $result->fetchOne() !== false;
			$result->closeCursor();
			return $found;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Jüngster Eintrag im Papierkorb der Team-Ordner zu dieser (alten) Datei-ID: Name und
	 * Löschzeitpunkt – daraus bildet groupfolders den Papierkorb-Namen „<name>.d<zeit>“.
	 * Teilen sich mehrere Einträge Ordner, Name und Zeitpunkt (ältere groupfolders ohne
	 * Eindeutigkeit), ist offen, wessen Inhalt dort liegt: dann null.
	 *
	 * @return array{name: string, time: int}|null null = kein (eindeutiger) Eintrag oder nicht feststellbar
	 */
	public function groupFolderTrashEntry(int $fileId): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('folder_id', 'name', 'deleted_time')->from('group_folders_trash')
				->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
				->orderBy('deleted_time', 'DESC')
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
			if ($row === false) {
				return null;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))->from('group_folders_trash')
				->where($qb->expr()->eq('folder_id', $qb->createNamedParameter((int)$row['folder_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('name', $qb->createNamedParameter((string)$row['name'])))
				->andWhere($qb->expr()->eq('deleted_time', $qb->createNamedParameter((int)$row['deleted_time'], IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			return $count === 1 ? ['name' => (string)$row['name'], 'time' => (int)$row['deleted_time']] : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Größe eines Eintrags laut Filecache (Ordner: aufsummiert von Nextcloud).
	 *
	 * @return int|float|null null = Eintrag fehlt; negative Werte = Größe unbekannt
	 */
	public function getSize(int $storageId, string $path): int|float|null {
		$qb = $this->db->getQueryBuilder();
		$qb->select('size')->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter(md5($path))));
		$result = $qb->executeQuery();
		$size = $result->fetchOne();
		$result->closeCursor();
		return $size === false ? null : 0 + $size;
	}

	/**
	 * Kennung eines Storages (oc_storages.id), z. B. „home::alice“ oder „object::user:alice“.
	 *
	 * @return string|null null = Storage unbekannt
	 */
	public function storageStringId(int $storageId): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('storages')
			->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false || $id === null ? null : (string)$id;
	}

	/**
	 * Quota eines Team-Ordners (oc_group_folders.quota) über seine Wurzel.
	 *
	 * @return int|null Rohwert (-3 unbegrenzt, -4 Standard aus groupfolders.quota.default);
	 *                  null = nicht feststellbar (groupfolders fehlt, ältere Tabelle ohne root_id)
	 */
	public function groupFolderQuota(int $rootId): ?int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('quota')->from('group_folders')
				->where($qb->expr()->eq('root_id', $qb->createNamedParameter($rootId, IQueryBuilder::PARAM_INT)));
			$result = $qb->executeQuery();
			$quota = $result->fetchOne();
			$result->closeCursor();
			return $quota === false || $quota === null ? null : (int)$quota;
		} catch (\Throwable) {
			return null;
		}
	}

	/** Zwischenspeicher leeren (zwischen Läufen/Bereichen; Ordner können verschoben werden) */
	public function reset(): void {
		$this->parents = [];
	}

	/**
	 * @return array{fileid: int, storage: int, path: string, name: string, parent: int, isFolder: bool, mtime: int}|null
	 */
	public function getEntry(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'storage', 'path', 'name', 'parent', 'mimetype', 'mtime')->from('filecache')
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return [
			'fileid' => (int)$row['fileid'],
			'storage' => (int)$row['storage'],
			'path' => (string)$row['path'],
			'name' => (string)$row['name'],
			'parent' => (int)$row['parent'],
			'isFolder' => (int)$row['mimetype'] === $this->folderMime(),
			'mtime' => (int)$row['mtime'],
		];
	}

	/** @param list<int> $fileIds @return array<int, string> fileid → name */
	public function getNames(array $fileIds): array {
		$names = [];
		foreach (array_chunk(array_values(array_unique($fileIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'name')->from('filecache')
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$names[(int)$row['fileid']] = (string)$row['name'];
			}
			$result->closeCursor();
		}
		return $names;
	}

	public function getIdByPath(int $storageId, string $path): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('path_hash', $qb->createNamedParameter(md5($path))));
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false ? null : (int)$id;
	}

	/**
	 * Gibt es direkt in Ordner $dir einen Eintrag „….d<$time>“ (Papierkorb-Name dieser Sekunde)?
	 * Nur für lange Namen gedacht – durchsucht alle Einträge des Papierkorbs.
	 */
	public function hasTrashEntryAt(int $storageId, string $dir, int $time): bool {
		$parentId = $this->getIdByPath($storageId, $dir);
		if ($parentId === null) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid')->from('filecache')
			->where($qb->expr()->eq('parent', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter('.d' . $time))))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}

	/**
	 * Summe der Versionen einer Datei im Home-Storage – files_trashbin verschiebt sie beim
	 * Löschen mit nach files_trashbin/versions. Gleiche Regel wie files_versions Storage::getVersions:
	 * „files/a/b.txt“ → Einträge „files_versions/a/b.txt.v<Zahl>“ im selben Ordner.
	 *
	 * @param string $filePath interner Pfad der Datei („files/…“)
	 * @return int|float|null 0 = keine Versionen; null = Größe einer Version unbekannt
	 */
	public function versionsSize(int $storageId, string $filePath): int|float|null {
		if (!str_starts_with($filePath, 'files/')) {
			return 0;
		}
		$rel = substr($filePath, strlen('files/'));
		$dir = dirname($rel);
		$parentId = $this->getIdByPath($storageId, $dir === '.' ? 'files_versions' : 'files_versions/' . $dir);
		if ($parentId === null) {
			return 0;
		}
		$base = basename($rel);
		$qb = $this->db->getQueryBuilder();
		$qb->select('name', 'size')->from('filecache')
			->where($qb->expr()->eq('parent', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('name', $qb->createNamedParameter($this->db->escapeLikeParameter($base) . '.v%')));
		$result = $qb->executeQuery();
		$sum = 0;
		$unknown = false;
		while ($row = $result->fetch()) {
			// „b.txt.v1.v2“ gehört zu „b.txt.v1“, nicht zu „b.txt“
			if (!preg_match('/^' . preg_quote($base, '/') . '\.v\d+$/', (string)$row['name'])) {
				continue;
			}
			$size = 0 + $row['size'];
			if ($size < 0) {
				$unknown = true;
			}
			$sum += max(0, $size);
		}
		$result->closeCursor();
		return $unknown ? null : $sum;
	}

	/**
	 * Direkte Unterordner, alphabetisch, mit Anzahl eigener Unterordner (für den Aufklapp-Pfeil).
	 *
	 * @return list<array{fileid: int, name: string, path: string, childFolders: int}>
	 */
	public function childFolders(int $parentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'name', 'path')->from('filecache')
			->where($qb->expr()->eq('parent', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($this->folderMime(), IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$folders = [];
		while ($row = $result->fetch()) {
			$folders[(int)$row['fileid']] = ['fileid' => (int)$row['fileid'], 'name' => (string)$row['name'], 'path' => (string)$row['path'], 'childFolders' => 0];
			$this->parents[(int)$row['fileid']] = $parentId;
		}
		$result->closeCursor();

		foreach (array_chunk(array_keys($folders), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('parent')->selectAlias($qb->func()->count('fileid'), 'n')->from('filecache')
				->where($qb->expr()->in('parent', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($this->folderMime(), IQueryBuilder::PARAM_INT)))
				->groupBy('parent');
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$folders[(int)$row['parent']]['childFolders'] = (int)$row['n'];
			}
			$result->closeCursor();
		}

		$folders = array_values($folders);
		usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
		return $folders;
	}

	/**
	 * Alle Ordner unterhalb eines Pfads (für „wie viele Unterordner übernehmen die Regel“).
	 *
	 * @return list<array{fileid: int, parent: int, path: string, name: string}>
	 */
	public function descendantFolders(int $storageId, string $path, int $limit = 20000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'parent', 'path', 'name')->from('filecache')
			->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('mimetype', $qb->createNamedParameter($this->folderMime(), IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		if ($path !== '') {
			$qb->andWhere($qb->expr()->like('path', $qb->createNamedParameter($this->db->escapeLikeParameter($path) . '/%')));
		}
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = ['fileid' => (int)$row['fileid'], 'parent' => (int)$row['parent'], 'path' => (string)$row['path'], 'name' => (string)$row['name']];
			$this->parents[(int)$row['fileid']] = (int)$row['parent'];
		}
		$result->closeCursor();
		return $out;
	}
}
