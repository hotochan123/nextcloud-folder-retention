<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Model\FileRow;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IDBConnection;

/**
 * Read-only direct access to oc_filecache. Deliberately without per-user filesystem setup:
 * the job evaluates storages, not user views.
 */
class FileCacheReader {
	private const MAX_DEPTH = 256;

	private ?int $folderMime = null;
	/** @var array<int, int> fileid → parent, folders only */
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
	 * Next batch of files of a storage below $pathPrefix, ordered by fileid.
	 *
	 * @param string $pathPrefix internal path without trailing slash; '' = whole storage
	 * @param bool $includeFolders also return folders (FileRow::$isFolder) – only for tag reconciliation
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

	/** Highest assigned file ID (0 for an empty file cache) – boundary between existing and new files, see Settings */
	public function maxFileId(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('fileid'))->from('filecache');
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();
		return (int)$max;
	}

	/**
	 * A single file fetched fresh from the DB (for the re-check immediately before deletion).
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
		return $qb->select('fc.fileid', 'fc.storage', 'fc.parent', 'fc.path', 'fc.mtime', 'fc.size', 'fc.etag', 'fc.mimetype', 'fe.creation_time', 'fe.upload_time', 'fs.first_seen')
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
			etag: (string)($row['etag'] ?? ''),
		);
	}

	/**
	 * Folder chain from $folderId upwards up to and including $stopAt.
	 *
	 * @return list<int>|null null if $stopAt is not reached (folder lies outside)
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
	 * Loads parent relations for the given folders and all their ancestors – batched per level,
	 * so that a batch of files needs only as many queries as the tree is deep.
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
			// not present (anymore) → treat as root
			$this->parents[$id] ??= -1;
		}
	}

	/**
	 * Like chain(), but without the cache: every level fetched fresh from the DB. For the re-check
	 * before deletion – a scan's cache can be hours old.
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
	 * Is the file ID in the team folders' trash bin (oc_group_folders_trash.file_id)?
	 *
	 * @return bool|null null = cannot be determined (groupfolders missing or older table without file_id)
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
	 * Most recent entry in the team folders' trash bin for this (old) file ID: name and
	 * deletion time – groupfolders builds the trash bin name "<name>.d<time>" from these.
	 * If several entries share folder, name and time (older groupfolders without a
	 * uniqueness guarantee), it is unclear whose content lies there: then null.
	 *
	 * @return array{name: string, time: int}|null null = no (unique) entry or cannot be determined
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
	 * Size of an entry according to the file cache (folders: summed up by Nextcloud).
	 *
	 * @return int|float|null null = entry missing; negative values = size unknown
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
	 * Identifier of a storage (oc_storages.id), e.g. "home::alice" or "object::user:alice".
	 *
	 * @return string|null null = storage unknown
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
	 * Quota of a team folder (oc_group_folders.quota), looked up via its root.
	 *
	 * @return int|null raw value (-3 unlimited, -4 default from groupfolders.quota.default);
	 *                  null = cannot be determined (groupfolders missing, older table without root_id)
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

	/** Clear the cache (between runs/scopes; folders can be moved) */
	public function reset(): void {
		$this->parents = [];
	}

	/**
	 * @return array{fileid: int, storage: int, path: string, name: string, parent: int, isFolder: bool, mtime: int, size: int, etag: string}|null
	 */
	public function getEntry(int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('fileid', 'storage', 'path', 'name', 'parent', 'mimetype', 'mtime', 'size', 'etag')->from('filecache')
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
			'size' => (int)$row['size'],
			'etag' => (string)$row['etag'],
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
	 * Is there an entry "….d<$time>" (trash bin name for this second) directly in folder $dir?
	 * Intended only for long names – searches all entries of the trash bin.
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
	 * Total size of a file's versions in the home storage – files_trashbin moves them along to
	 * files_trashbin/versions on deletion. Same rule as files_versions Storage::getVersions:
	 * "files/a/b.txt" → entries "files_versions/a/b.txt.v<number>" in the same folder.
	 *
	 * @param string $filePath internal path of the file ("files/…")
	 * @return int|float|null 0 = no versions; null = size of a version unknown
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
			// "b.txt.v1.v2" belongs to "b.txt.v1", not to "b.txt"
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
	 * Direct subfolders, alphabetical, with the number of their own subfolders (for the expand arrow).
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
	 * All folders below a path (for "how many subfolders inherit the rule").
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
