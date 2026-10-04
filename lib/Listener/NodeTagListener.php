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
 * Neue, kopierte und verschobene Dateien/Ordner sofort taggen.
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
				// Umbenennen im selben Ordner ändert nichts an der geltenden Frist
				if (dirname($event->getSource()->getPath()) !== dirname($event->getTarget()->getPath())) {
					$this->tagger->tag($event->getTarget(), true);
				}
			}
		} catch (\Throwable $e) {
			// Tags sind nur Anzeige – Uploads und Verschieben dürfen daran nie scheitern
			$this->logger->warning('folder_retention: could not set tag', ['exception' => $e]);
		}
	}
}
