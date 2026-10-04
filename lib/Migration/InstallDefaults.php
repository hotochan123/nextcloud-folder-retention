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
 * On install and after updates (idempotent): switch simulation mode ON if it was never
 * set, and create the default rules (general, personal folders) if they are missing –
 * both newly created with "Never delete". Existing rules are left untouched by this step.
 * Also, once, the boundary between existing and new files (highest file ID now): files created from here on,
 * including copies with an inherited upload time, count at the earliest from when they are first seen. If the instance comes
 * from the early 0.8.0 state (seen_since = point in time), the boundary is derived from that – otherwise
 * copies from that period would count as existing files and be due immediately.
 * Safety locks from the app config (interim stage before 0.8.0) move into their own table.
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
		// only now: seen_max_fileid is set, the old timestamp is no longer needed
		$this->settings->dropLegacySeenSince();
		$this->settings->migrateLegacyBlocks();
		$this->rules->ensureDefault();
		$this->rules->ensurePersonalDefault();
	}
}
