<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use OCA\FolderRetention\Service\Settings;
use OCP\IL10N;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Uninstall-Schritt: läuft bei jedem Deaktivieren (occ app:disable, Weboberfläche, automatisches
 * Abschalten beim Nextcloud-Update) und bei occ app:remove ohne --keep-data.
 *
 * Nextcloud löscht beim Entfernen weder App-Config noch Tabellen noch Migrationsstand. Eine
 * spätere Neuinstallation liefe deshalb mit den alten Einstellungen weiter – auch mit
 * ausgeschalteter Simulation, also schon in der ersten Nacht scharf nach den alten Fristen.
 * Darum hier den Simulationsmodus einschalten: Wer die App wieder aktiviert, schaltet bewusst scharf.
 * Regeln, Protokoll und alles andere bleiben unverändert.
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
		// immer schreiben: auch „nie gesetzt“ wird so zu einem festen AN
		$this->settings->setSimulation(true);
		$output->info($this->l->t('folder_retention: simulation mode switched on – after re-enabling nothing is deleted until it is deliberately switched off'));
	}
}
