<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * A file (or, during tag sync, a folder) from oc_filecache (+ oc_filecache_extended).
 */
final class FileRow {
	public function __construct(
		public readonly int $fileId,
		public readonly int $storageId,
		public readonly int $parentId,
		public readonly string $path,
		public readonly int $mtime,
		public readonly ?int $creationTime,
		public readonly ?int $uploadTime,
		public readonly int $size = 0,
		/** tag sync only: folders are read along but never evaluated or deleted */
		public readonly bool $isFolder = false,
		/** "first seen" as recorded by the app (folder_retention_seen), null = no entry */
		public readonly ?int $firstSeen = null,
		/** last real deletion of this file ID according to the log, null = never */
		public readonly ?int $lastDeleted = null,
		/**
		 * Settings::seenMaxFileId() – file IDs above it were created after the update; for them
		 * "first seen" also counts alongside upload_time. null = not passed (only without upload_time)
		 */
		public readonly ?int $seenMaxFileId = null,
	) {
	}

	/** Copy with "first seen" and last deletion (both come from the app's own tables) */
	public function with(?int $firstSeen, ?int $lastDeleted, ?int $seenMaxFileId = null): self {
		return new self($this->fileId, $this->storageId, $this->parentId, $this->path, $this->mtime,
			$this->creationTime, $this->uploadTime, $this->size, $this->isFolder, $firstSeen, $lastDeleted, $seenMaxFileId);
	}

	/** Created after the update to 0.8 (new file ID, e.g. a copy)? */
	public function isNewSinceMark(): bool {
		return $this->seenMaxFileId !== null && $this->fileId > $this->seenMaxFileId;
	}

	/** Does Nextcloud know when the file was stored? Otherwise basis=created needs "first seen". */
	public function hasUploadTime(): bool {
		return $this->uploadTime !== null && $this->uploadTime > 0;
	}

	public function referenceDate(Basis $basis, int $now): ?ReferenceDate {
		return ReferenceDate::fromFileCache($basis, $this->creationTime, $this->uploadTime, $this->mtime, $this->firstSeen, $this->lastDeleted, $now, $this->isNewSinceMark());
	}
}
