<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

/**
 * The run lock no longer belongs to this run (expired and taken over by another
 * run). The run aborts instead of continuing to delete in parallel.
 */
class RunLockLostException extends \RuntimeException {
	public function __construct(
		/** current holder of the lock, if known */
		public readonly ?string $holder = null,
	) {
		parent::__construct('Run lock lost during the run' . ($holder !== null ? ', now held by ' . $holder : ''));
	}
}
