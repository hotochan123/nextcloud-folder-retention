<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use InvalidArgumentException;
use OCA\FolderRetention\Model\Resolution;
use OCA\FolderRetention\Model\RetentionRule;

/**
 * Pure resolution logic – no file system, no DB.
 *
 * For a file F with direct parent folder P:
 *  1. If P has a rule, it applies – regardless of its scope.
 *  2. Otherwise walk upwards: the first ancestor with a rule AND scope=inherit wins;
 *     rules with scope=here are skipped.
 *  3. Otherwise the default rule applies.
 *
 * The same function provides the "effective rule of a folder" for the UI:
 * that is the rule that applies to files directly in this folder, i.e.
 * resolve([folder, parent, grandparent, …]).
 */
class RuleResolver {

	/**
	 * @param list<int> $folderChain folder IDs from the file's direct parent folder upwards
	 *                               to the root, e.g. [P, P.parent, …]
	 * @param array<int, RetentionRule> $rulesByFolderId folder rules, key = folderId
	 */
	public function resolve(array $folderChain, array $rulesByFolderId, RetentionRule $default): Resolution {
		return $this->walk($folderChain, $rulesByFolderId, $default, true);
	}

	/**
	 * Rule that a (hypothetical) subfolder WITHOUT its own rule would inherit from
	 * $folderChain[0]. For the UI line "Subfolders continue to inherit from …" with scope=here.
	 * The depth in the result is relative to $folderChain[0] (0 = this folder).
	 *
	 * @param list<int> $folderChain
	 * @param array<int, RetentionRule> $rulesByFolderId
	 */
	public function resolveForChildren(array $folderChain, array $rulesByFolderId, RetentionRule $default): Resolution {
		return $this->walk($folderChain, $rulesByFolderId, $default, false);
	}

	/**
	 * @param list<int> $folderChain
	 * @param array<int, RetentionRule> $rulesByFolderId
	 */
	private function walk(array $folderChain, array $rulesByFolderId, RetentionRule $default, bool $directParentAnyScope): Resolution {
		if (!$default->isDefault()) {
			throw new InvalidArgumentException('Default rule expected (folderId = null)');
		}

		foreach (array_values($folderChain) as $depth => $folderId) {
			$rule = $rulesByFolderId[$folderId] ?? null;
			if ($rule === null) {
				continue;
			}
			if ($rule->folderId !== $folderId) {
				throw new InvalidArgumentException("Rule under key $folderId belongs to folder {$rule->folderId}");
			}
			if (($depth === 0 && $directParentAnyScope) || $rule->inherits()) {
				return new Resolution($rule, $depth);
			}
			// scope=here on an ancestor: skip
		}

		return new Resolution($default, null);
	}
}
