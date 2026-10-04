<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * Ergebnis der Regelauflösung: welche Regel gilt und woher sie kommt.
 */
final class Resolution {
	public function __construct(
		public readonly RetentionRule $rule,
		/**
		 * Abstand zum Ordner, an dem die Regel hängt: 0 = direkter Elternordner
		 * (bzw. der abgefragte Ordner selbst), 1 = dessen Elternordner, …;
		 * null = Standardregel.
		 */
		public readonly ?int $depth,
	) {
	}

	/** Regel hängt direkt am abgefragten Ordner („Eigene Regel“) */
	public function isOwn(): bool {
		return $this->depth === 0;
	}

	public function isDefault(): bool {
		return $this->depth === null;
	}

	/** Ordner-ID, von dem geerbt wird; null bei Standardregel */
	public function sourceFolderId(): ?int {
		return $this->rule->folderId;
	}
}
