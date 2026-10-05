<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Log entries remember their area by a stable key (RetentionRoot::blockKey(): storage and root
 * folder) besides the display path. The display path starts with the area's name, which can
 * change (display name of a workspace account, renamed Team folder) or be the same for two
 * areas – the log overview groups by key and name instead. Older entries keep NULL and are
 * grouped by their path as before.
 */
class Version1004Date20261005000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('folder_retention_log')) {
			return null;
		}
		$table = $schema->getTable('folder_retention_log');
		if ($table->hasColumn('root_key')) {
			return null;
		}
		$table->addColumn('root_key', Types::STRING, ['notnull' => false, 'length' => 64]);
		return $schema;
	}
}
