<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * Result of rule resolution: which rule applies and where it comes from.
 */
final class Resolution {
	public function __construct(
		public readonly RetentionRule $rule,
		/**
		 * Distance to the folder the rule is attached to: 0 = direct parent folder
		 * (or the queried folder itself), 1 = its parent folder, …;
		 * null = default rule.
		 */
		public readonly ?int $depth,
	) {
	}

	/** Rule is attached directly to the queried folder ("Own rule") */
	public function isOwn(): bool {
		return $this->depth === 0;
	}

	public function isDefault(): bool {
		return $this->depth === null;
	}

	/** Folder ID inherited from; null for the default rule */
	public function sourceFolderId(): ?int {
		return $this->rule->folderId;
	}
}
