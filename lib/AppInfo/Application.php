<?php

declare(strict_types=1);

namespace OCA\FolderRetention\AppInfo;

use OCA\FolderRetention\Listener\DavPluginListener;
use OCA\FolderRetention\Listener\FilesScriptListener;
use OCA\FolderRetention\Listener\NodeTagListener;
use OCA\FolderRetention\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\User\Events\BeforeUserDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'folder_retention';

	/** App config keys (IAppConfig) */
	public const CONFIG_SIMULATION = 'simulation_mode';
	public const CONFIG_INCLUDE_PERSONAL = 'default_applies_to_personal';
	public const CONFIG_TAGS = 'tags_enabled';
	public const CONFIG_LOG_RETENTION = 'log_retention_days';
	public const CONFIG_DELETION_LIMIT = 'deletion_limit';
	public const CONFIG_FILES_INFO = 'files_info';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(NodeCreatedEvent::class, NodeTagListener::class);
		$context->registerEventListener(NodeCopiedEvent::class, NodeTagListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, NodeTagListener::class);
		$context->registerEventListener(BeforeUserDeletedEvent::class, UserDeletedListener::class);
		// deletion date in the Files app; class names as strings – dav and files are other apps
		$context->registerEventListener('OCA\\DAV\\Events\\SabrePluginAddEvent', DavPluginListener::class);
		$context->registerEventListener('OCA\\Files\\Event\\LoadAdditionalScriptsEvent', FilesScriptListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
