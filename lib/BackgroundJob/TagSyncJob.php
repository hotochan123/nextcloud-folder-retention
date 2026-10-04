<?php

declare(strict_types=1);

namespace OCA\FolderRetention\BackgroundJob;

use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Einmaliger Tag-Abgleich nach einer Regeländerung oder einem verschobenen Ordner.
 * Argument: ['folderId' => int|null] – null = alle Bereiche (Standardregel geändert, Tags eingeschaltet).
 * IJobList::add() legt pro Argument höchstens einen wartenden Job an.
 */
class TagSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private RetentionRunner $runner,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		if (!$this->settings->tagsEnabled()) {
			return;
		}
		$folderId = is_array($argument) && isset($argument['folderId']) ? (int)$argument['folderId'] : null;
		$stats = $this->runner->syncTags($folderId);
		$this->logger->info('folder_retention: tag sync ' . ($folderId === null ? 'complete' : 'folder ' . $folderId)
			. sprintf(' – +%d/−%d', $stats->tagsAdded, $stats->tagsRemoved));
	}
}
