<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Listener;

use OCA\FolderRetention\Service\NodeTagger;
use OCA\FolderRetention\Service\Settings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use Psr\Log\LoggerInterface;

/**
 * Tag new, copied and moved files/folders immediately.
 *
 * @template-implements IEventListener<Event>
 */
class NodeTagListener implements IEventListener {
	public function __construct(
		private Settings $settings,
		private NodeTagger $tagger,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->settings->tagsEnabled()) {
			return;
		}
		try {
			if ($event instanceof NodeCreatedEvent) {
				$this->tagger->tag($event->getNode(), false);
			} elseif ($event instanceof NodeCopiedEvent) {
				$this->tagger->tag($event->getTarget(), true);
			} elseif ($event instanceof NodeRenamedEvent) {
				// Renaming within the same folder doesn't change the applicable retention period
				if (dirname($event->getSource()->getPath()) !== dirname($event->getTarget()->getPath())) {
					$this->tagger->tag($event->getTarget(), true);
				}
			}
		} catch (\Throwable $e) {
			// Tags are display only – uploads and moves must never fail because of them
			$this->logger->warning('folder_retention: could not set tag', ['exception' => $e]);
		}
	}
}
