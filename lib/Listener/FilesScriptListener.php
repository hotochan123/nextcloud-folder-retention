<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FolderRetention\AppInfo\Application;
use OCA\FolderRetention\Service\Settings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the badge and the sidebar tab into the Files app – only when the setting is on.
 *
 * @template-implements IEventListener<Event>
 */
class FilesScriptListener implements IEventListener {
	public function __construct(
		private Settings $settings,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent) || !$this->settings->filesInfoEnabled()) {
			return;
		}
		Util::addInitScript(Application::APP_ID, Application::APP_ID . '-files');
		Util::addTranslations(Application::APP_ID);
	}
}
