<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use OCA\FolderRetention\Service\Settings;
use OCP\IL10N;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Uninstall step: runs on every disable (occ app:disable, web UI, automatic
 * disabling during a Nextcloud update) and on occ app:remove without --keep-data.
 *
 * On removal Nextcloud deletes neither the app config nor the tables nor the migration state. A
 * later reinstall would therefore carry on with the old settings – including with
 * simulation switched off, i.e. deleting for real by the old retention periods from the very first night.
 * So switch simulation mode on here: whoever re-enables the app must deliberately switch to live deletion.
 * Rules, log and everything else stay unchanged.
 */
class SimulationOnDisable implements IRepairStep {
	public function __construct(
		private Settings $settings,
		private IL10N $l,
	) {
	}

	public function getName(): string {
		return $this->l->t('Folder retention: switch on simulation mode for a later re-activation');
	}

	public function run(IOutput $output): void {
		// always write: this also turns "never set" into an explicit ON
		$this->settings->setSimulation(true);
		$output->info($this->l->t('folder_retention: simulation mode switched on – after re-enabling nothing is deleted until it is deliberately switched off'));
	}
}
