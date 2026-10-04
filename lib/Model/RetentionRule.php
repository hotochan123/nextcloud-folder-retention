<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

use InvalidArgumentException;
use OCP\IL10N;

/**
 * Unveränderliche Regel, wie sie der Resolver sieht (entkoppelt von der DB-Entity).
 */
final class RetentionRule {
	public function __construct(
		public readonly ?int $id,
		/** null = Standardregel */
		public readonly ?int $folderId,
		public readonly Period $period,
		/** null nur bei der Standardregel */
		public readonly ?Scope $scope,
		public readonly Basis $basis = Basis::Created,
		public readonly bool $notify = false,
		/** Standardregel für persönliche Ordner (statt der allgemeinen) */
		public readonly bool $personal = false,
	) {
		if ($folderId === null && $scope !== null) {
			throw new InvalidArgumentException('The default rule has no scope');
		}
		if ($folderId !== null && $scope === null) {
			throw new InvalidArgumentException('Folder rules need a scope');
		}
	}

	public function isDefault(): bool {
		return $this->folderId === null;
	}

	/** Label for the log, e.g. "Personal default: 1 week" (German: „Standard persönlich: 1 Woche“) */
	public function logLabel(IL10N $l): string {
		$label = $this->period->label($l);
		return match (true) {
			$this->personal => $l->t('Personal default: %s', [$label]),
			$this->isDefault() => $l->t('Default: %s', [$label]),
			default => $label,
		};
	}

	public function inherits(): bool {
		return $this->scope === Scope::Inherit;
	}
}
