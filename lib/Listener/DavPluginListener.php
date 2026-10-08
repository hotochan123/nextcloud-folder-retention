<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FolderRetention\Dav\DeletionInfoPlugin;
use OCA\FolderRetention\Service\Settings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;

/**
 * Adds the WebDAV property with the deletion date (DeletionInfoPlugin) – only when the
 * setting is on.
 *
 * @template-implements IEventListener<Event>
 */
class DavPluginListener implements IEventListener {
	public function __construct(
		private Settings $settings,
		private ContainerInterface $container,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof SabrePluginAddEvent) || !$this->settings->filesInfoEnabled()) {
			return;
		}
		$event->getServer()->addPlugin($this->container->get(DeletionInfoPlugin::class));
	}
}
