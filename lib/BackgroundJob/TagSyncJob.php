<?php

declare(strict_types=1);

namespace OCA\FolderRetention\BackgroundJob;

use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-off tag sync after a rule change or a moved folder.
 * Argument: ['folderId' => int|null] – null = all areas (default rule changed, tags switched on).
 * IJobList::add() queues at most one pending job per argument.
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
