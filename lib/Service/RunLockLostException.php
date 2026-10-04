<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

/**
 * Die Laufsperre gehört nicht mehr diesem Lauf (abgelaufen und von einem anderen Lauf
 * übernommen). Der Lauf bricht ab, statt parallel weiterzulöschen.
 */
class RunLockLostException extends \RuntimeException {
	public function __construct(
		/** current holder of the lock, if known */
		public readonly ?string $holder = null,
	) {
		parent::__construct('Run lock lost during the run' . ($holder !== null ? ', now held by ' . $holder : ''));
	}
}
