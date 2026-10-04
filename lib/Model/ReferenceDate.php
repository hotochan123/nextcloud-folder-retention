<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

final class ReferenceDate {
	public const SOURCE_CREATED = 'created';
	public const SOURCE_UPLOAD = 'upload';
	public const SOURCE_MTIME = 'mtime';
	/** "first seen" – recorded by the app because Nextcloud knows no upload time */
	public const SOURCE_SEEN = 'seen';
	/** restored from the trash bin: time of the last real deletion by the app */
	public const SOURCE_RESTORED = 'restored';

	public function __construct(
		public readonly int $timestamp,
		/** self::SOURCE_* – which field was actually used */
		public readonly string $source,
	) {
	}

	/**
	 * Reference date from the file cache fields.
	 *
	 * basis=created means "since stored in Nextcloud": max(upload_time, creation_time).
	 *   creation_time alone is not suitable – it is the creation time reported by the client
	 *   (X-OC-CTime), i.e. years back when old files are synced. If upload_time is missing
	 *   (occ files:scan, legacy files, created server-side), the "first seen" date recorded
	 *   by the app applies; without it there is no reference date. No fallback to mtime.
	 *   Copies inherit upload_time and creation_time from the original (Cache::copyFromCache) – a
	 *   copy created today would otherwise look "stored years ago". That's why "first seen" also
	 *   counts alongside upload_time when $isNew: the file ID is above the highest ID remembered at
	 *   the update or install (Settings::seenMaxFileId) – copies always get
	 *   a new ID. Files up to that ID are existing files: for them the upload time applies.
	 * basis=modified: mtime.
	 *
	 * All values are capped to <= $now (future values would otherwise never become due, or at the wrong time).
	 * If the file was already deleted by the app once and restored afterwards, the retention period
	 * counts from the restore: RetentionRunner::enrich resets "first seen" on the first
	 * sighting after the deletion – if first_seen is after the deletion, first_seen applies (also
	 * alongside upload_time and regardless of $isNew). Without that, the deletion counts at the earliest –
	 * if the restore came more than one retention period after it, the file would otherwise be gone again immediately.
	 *
	 * Values <= 0 or null count as "not set". Returns null if no value
	 * is usable – such files are never deleted.
	 */
	public static function fromFileCache(Basis $basis, ?int $creationTime, ?int $uploadTime, ?int $mtime, ?int $firstSeen, ?int $lastDeleted, int $now, bool $isNew = false): ?self {
		$set = static fn (?int $v): bool => $v !== null && $v > 0;

		if ($basis === Basis::Created) {
			if ($set($uploadTime)) {
				$ref = $set($creationTime) && $creationTime > $uploadTime
					? new self($creationTime, self::SOURCE_CREATED)
					: new self($uploadTime, self::SOURCE_UPLOAD);
				if ($isNew && $set($firstSeen) && $firstSeen > $ref->timestamp) {
					$ref = new self($firstSeen, self::SOURCE_SEEN);
				}
			} elseif ($set($firstSeen)) {
				$ref = new self($firstSeen, self::SOURCE_SEEN);
			} else {
				return null;
			}
		} elseif ($set($mtime)) {
			$ref = new self($mtime, self::SOURCE_MTIME);
		} else {
			return null;
		}

		if ($ref->timestamp > $now) {
			$ref = new self($now, $ref->source);
		}
		if ($set($lastDeleted) && $lastDeleted > $ref->timestamp) {
			$restored = $set($firstSeen) && $firstSeen > $lastDeleted ? $firstSeen : $lastDeleted;
			$ref = new self(min($restored, $now), self::SOURCE_RESTORED);
		}
		return $ref;
	}
}
