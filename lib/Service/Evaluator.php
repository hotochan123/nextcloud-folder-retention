<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use DateTimeZone;
use OCA\FolderRetention\Model\Decision;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\RuleSet;

/**
 * Evaluates a file based on its folder chain. Pure – no DB, no file system.
 */
class Evaluator {
	public function __construct(
		private RuleResolver $resolver,
	) {
	}

	/**
	 * @param list<int> $folderChain direct parent folder … area root
	 * @param bool $defaultApplies false = default rule does not apply here (personal files, setting off)
	 * @param int $now future values in the reference date are capped to this
	 */
	public function evaluate(FileRow $file, array $folderChain, RuleSet $rules, bool $defaultApplies, DateTimeZone $tz, int $now): Decision {
		$resolution = $this->resolver->resolve($folderChain, $rules->byFolderId, $rules->default);
		$rule = $resolution->rule;

		if ($resolution->isDefault() && !$defaultApplies) {
			return new Decision($file, $resolution, null, null, Decision::SKIP_PERSONAL);
		}
		if ($rule->period->isNever()) {
			return new Decision($file, $resolution, null, null, Decision::SKIP_NEVER);
		}
		$reference = $file->referenceDate($rule->basis, $now);
		if ($reference === null) {
			return new Decision($file, $resolution, null, null, Decision::SKIP_NO_DATE);
		}
		return new Decision($file, $resolution, $reference, $rule->period->expiresAt($reference->timestamp, $tz));
	}
}
