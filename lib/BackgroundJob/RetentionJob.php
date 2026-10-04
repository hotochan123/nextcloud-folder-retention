<?php

declare(strict_types=1);

namespace OCA\FolderRetention\BackgroundJob;

use OCA\FolderRetention\Service\RetentionRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Täglicher Aufbewahrungslauf.
 *
 * Der Job wird alle 15 Minuten angestoßen, arbeitet aber nur, solange ein Zyklus offen ist:
 * Ein Zyklus beginnt frühestens 23 h nach dem Ende des letzten und wird in Häppchen
 * (Zeitbudget, Cursor) abgearbeitet, damit große Instanzen cron.php nicht blockieren.
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
