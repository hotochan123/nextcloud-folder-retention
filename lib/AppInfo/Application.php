<?php

declare(strict_types=1);

namespace OCA\FolderRetention\AppInfo;

use OCA\FolderRetention\Listener\NodeTagListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'folder_retention';

	/** App config keys (IAppConfig) */
	public const CONFIG_SIMULATION = 'simulation_mode';
	public const CONFIG_INCLUDE_PERSONAL = 'default_applies_to_personal';
	public const CONFIG_TAGS = 'tags_enabled';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(NodeCreatedEvent::class, NodeTagListener::class);
		$context->registerEventListener(NodeCopiedEvent::class, NodeTagListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, NodeTagListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
