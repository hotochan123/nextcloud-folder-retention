<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Listener;

use OCA\FolderRetention\Service\LogRetention;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\BeforeUserDeletedEvent;

/**
 * Before an account is deleted (its home storage still exists): remove its log entries.
 *
 * @template-implements IEventListener<BeforeUserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private LogRetention $logRetention,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeUserDeletedEvent) {
			$this->logRetention->forgetUser($event->getUser()->getUID());
		}
	}
}
