<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\FileCacheReader;
use OCA\FolderRetention\Service\FirstSeen;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCP\IL10N;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Bei Installation und nach Updates (idempotent): Simulationsmodus AN, sofern noch nie
 * gesetzt, und Standardregeln (allgemein, persönliche Ordner) anlegen, falls sie fehlen –
 * neu angelegt beide mit „Nie löschen“. Vorhandene Regeln fasst der Schritt nicht an.
 * Dazu einmalig die Grenze Bestand/neu (höchste Datei-ID jetzt): Ab hier entstandene Dateien,
 * auch Kopien mit geerbter Upload-Zeit, zählen frühestens ab dem ersten Sehen. Kommt die Instanz
 * vom frühen 0.8.0-Stand (seen_since = Zeitpunkt), wird die Grenze daraus abgeleitet – sonst
 * zählten Kopien aus jener Zeit als Bestand und wären sofort fällig.
 * Sicherheitssperren aus der App-Config (Vorstufe von 0.8.0) wandern in ihre Tabelle.
 * Fixes the language of stored texts and tag names once (ContentLanguage) – before the default
 * rules are created, because existing rules mark an install from the German-only era.
 */
class InstallDefaults implements IRepairStep {
	public function __construct(
		private Settings $settings,
		private RuleService $rules,
		private FileCacheReader $fileCache,
		private FirstSeen $firstSeen,
		private ContentLanguage $language,
		private IL10N $l,
	) {
	}

	public function getName(): string {
		return $this->l->t('Folder retention: enable simulation mode, create default rules');
	}

	public function run(IOutput $output): void {
		$this->language->initialize();
		$this->settings->initSimulation();
		if ($this->settings->seenMaxFileId() === null) {
			$since = $this->settings->legacySeenSince();
			$mark = $since === null ? null : $this->firstSeen->lastIdSeenUntil($since);
			$this->settings->initSeenMaxFileId($mark ?? $this->fileCache->maxFileId());
		}
		// erst jetzt: seen_max_fileid steht, der alte Zeitpunkt wird nicht mehr gebraucht
		$this->settings->dropLegacySeenSince();
		$this->settings->migrateLegacyBlocks();
		$this->rules->ensureDefault();
		$this->rules->ensurePersonalDefault();
	}
}
