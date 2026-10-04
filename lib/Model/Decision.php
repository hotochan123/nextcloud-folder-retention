<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * Bewertung einer Datei: welche Regel gilt, ab wann sie gelöscht werden darf.
 */
final class Decision {
	public const SKIP_NEVER = 'never';
	public const SKIP_NO_DATE = 'no_date';
	public const SKIP_PERSONAL = 'personal_default';

	public function __construct(
		public readonly FileRow $file,
		public readonly Resolution $resolution,
		public readonly ?ReferenceDate $reference,
		/** null = wird nie gelöscht (siehe $skipReason) */
		public readonly ?int $expiresAt,
		public readonly ?string $skipReason = null,
	) {
	}

	public function isDueAt(int $now): bool {
		return $this->expiresAt !== null && $this->expiresAt <= $now;
	}
}
