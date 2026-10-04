<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

final class ReferenceDate {
	public const SOURCE_CREATED = 'created';
	public const SOURCE_UPLOAD = 'upload';
	public const SOURCE_MTIME = 'mtime';
	/** „zuerst gesehen“ – von der App vermerkt, weil Nextcloud keine Upload-Zeit kennt */
	public const SOURCE_SEEN = 'seen';
	/** aus dem Papierkorb zurückgeholt: Zeitpunkt der letzten echten Löschung durch die App */
	public const SOURCE_RESTORED = 'restored';

	public function __construct(
		public readonly int $timestamp,
		/** self::SOURCE_* – welches Feld tatsächlich verwendet wurde */
		public readonly string $source,
	) {
	}

	/**
	 * Bezugsdatum aus den Filecache-Feldern.
	 *
	 * basis=created heißt „seit Ablage in Nextcloud“: max(upload_time, creation_time).
	 *   creation_time allein taugt nicht – das ist die Erstellzeit, die der Client meldet
	 *   (X-OC-CTime), bei einem Sync alter Dateien also Jahre zurück. Fehlt upload_time
	 *   (occ files:scan, Altbestand, serverseitig angelegt), gilt das von der App vermerkte
	 *   „zuerst gesehen“-Datum; ohne das gibt es kein Bezugsdatum. Kein Rückfall auf mtime.
	 *   Kopien erben upload_time und creation_time des Originals (Cache::copyFromCache) – eine
	 *   heute angelegte Kopie sähe sonst „vor Jahren abgelegt“ aus. Darum zählt „zuerst gesehen“
	 *   auch neben upload_time mit, wenn $isNew: Die Datei-ID liegt über der beim Update bzw. bei
	 *   der Installation gemerkten höchsten ID (Settings::seenMaxFileId) – Kopien bekommen immer
	 *   eine neue ID. Dateien bis zu dieser ID sind Bestand: für sie gilt die Upload-Zeit.
	 * basis=modified: mtime.
	 *
	 * Alle Werte werden auf <= $now gekappt (Zukunftswerte würden sonst nie bzw. falsch fällig).
	 * Wurde die Datei schon einmal von der App gelöscht und danach wiederhergestellt, zählt die
	 * Frist ab der Wiederherstellung: RetentionRunner::enrich setzt „zuerst gesehen“ beim ersten
	 * Sehen nach der Löschung neu – liegt first_seen nach der Löschung, gilt first_seen (auch
	 * neben upload_time und unabhängig von $isNew). Ohne das zählt frühestens die Löschung –
	 * kam die Wiederherstellung später als eine Frist danach, wäre die Datei sonst sofort wieder weg.
	 *
	 * Werte <= 0 oder null gelten als „nicht gesetzt“. Liefert null, wenn kein Wert
	 * brauchbar ist – solche Dateien werden nie gelöscht.
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
