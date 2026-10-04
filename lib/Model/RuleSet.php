<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * All rules at one point in time – a run works with the same snapshot throughout.
 */
final class RuleSet {
	/**
	 * @param array<int, RetentionRule> $byFolderId
	 * @param RetentionRule|null $personal default rule for personal folders; null = $default applies there
	 */
	public function __construct(
		public readonly RetentionRule $default,
		public readonly array $byFolderId,
		public readonly ?RetentionRule $personal = null,
	) {
	}

	/**
	 * Rule set from the perspective of an area: in personal folders the personal
	 * default rule takes the place of the general one.
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
