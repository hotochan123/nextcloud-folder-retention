<?php

declare(strict_types=1);

namespace OCA\FolderRetention\BackgroundJob;

use OCA\FolderRetention\Service\RetentionRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily retention run.
 *
 * The job is triggered every 15 minutes but only does work while a cycle is open:
 * a cycle starts no earlier than 23 h after the previous one ended and is processed in small chunks
 * (time budget, cursor) so that large instances don't block cron.php.
 */
class RetentionJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private RetentionRunner $runner,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->runner->runScheduled();
	}
}
