<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * Alle Regeln zu einem Zeitpunkt – ein Lauf arbeitet durchgehend mit demselben Stand.
 */
final class RuleSet {
	/**
	 * @param array<int, RetentionRule> $byFolderId
	 * @param RetentionRule|null $personal Standardregel für persönliche Ordner; null = dort gilt $default
	 */
	public function __construct(
		public readonly RetentionRule $default,
		public readonly array $byFolderId,
		public readonly ?RetentionRule $personal = null,
	) {
	}

	/**
	 * Regelsatz aus Sicht eines Bereichs: In persönlichen Ordnern tritt die persönliche
	 * Standardregel an die Stelle der allgemeinen.
	 */
	public function forRoot(RetentionRoot $root): self {
		if ($root->isHome() && $this->personal !== null) {
			return new self($this->personal, $this->byFolderId, $this->personal);
		}
		return $this;
	}

	public function byId(int $ruleId): ?RetentionRule {
		foreach ([$this->default, $this->personal] as $rule) {
			if ($rule !== null && $rule->id === $ruleId) {
				return $rule;
			}
		}
		foreach ($this->byFolderId as $rule) {
			if ($rule->id === $ruleId) {
				return $rule;
			}
		}
		return null;
	}
}
