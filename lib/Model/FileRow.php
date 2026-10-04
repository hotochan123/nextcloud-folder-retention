<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * Eine Datei (oder beim Tag-Abgleich ein Ordner) aus oc_filecache (+ oc_filecache_extended).
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
		/** nur bei Tag-Abgleich: Ordner werden mitgelesen, aber nie bewertet oder gelöscht */
		public readonly bool $isFolder = false,
		/** von der App vermerktes „zuerst gesehen“ (folder_retention_seen), null = kein Eintrag */
		public readonly ?int $firstSeen = null,
		/** letzte echte Löschung dieser Datei-ID laut Protokoll, null = nie */
		public readonly ?int $lastDeleted = null,
		/**
		 * Settings::seenMaxFileId() – Datei-IDs darüber sind nach dem Update entstanden, für sie
		 * zählt „zuerst gesehen“ auch neben upload_time. null = nicht übergeben (nur ohne upload_time)
		 */
		public readonly ?int $seenMaxFileId = null,
	) {
	}

	/** Kopie mit „zuerst gesehen“ und letzter Löschung (beides kommt aus eigenen Tabellen) */
	public function with(?int $firstSeen, ?int $lastDeleted, ?int $seenMaxFileId = null): self {
		return new self($this->fileId, $this->storageId, $this->parentId, $this->path, $this->mtime,
			$this->creationTime, $this->uploadTime, $this->size, $this->isFolder, $firstSeen, $lastDeleted, $seenMaxFileId);
	}

	/** Nach dem Update auf 0.8 entstanden (neue Datei-ID, z. B. Kopie)? */
	public function isNewSinceMark(): bool {
		return $this->seenMaxFileId !== null && $this->fileId > $this->seenMaxFileId;
	}

	/** Kennt Nextcloud den Zeitpunkt der Ablage? Sonst braucht basis=created „zuerst gesehen“. */
	public function hasUploadTime(): bool {
		return $this->uploadTime !== null && $this->uploadTime > 0;
	}

	public function referenceDate(Basis $basis, int $now): ?ReferenceDate {
		return ReferenceDate::fromFileCache($basis, $this->creationTime, $this->uploadTime, $this->mtime, $this->firstSeen, $this->lastDeleted, $now, $this->isNewSinceMark());
	}
}
