<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use DateTimeZone;
use OCA\FolderRetention\Model\Decision;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\RuleSet;

/**
 * Bewertet eine Datei anhand ihrer Ordnerkette. Rein – keine DB, kein Dateisystem.
 */
class Evaluator {
	public function __construct(
		private RuleResolver $resolver,
	) {
	}

	/**
	 * @param list<int> $folderChain direkter Elternordner … Bereichswurzel
	 * @param bool $defaultApplies false = Standardregel greift hier nicht (persönliche Dateien, Einstellung aus)
	 * @param int $now Zukunftswerte im Bezugsdatum werden darauf gekappt
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
