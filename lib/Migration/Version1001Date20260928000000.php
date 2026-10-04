<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Second default rule for personal folders: column "target" distinguishes the two
 * default rules (folder_id = NULL). NULL = general default rule or folder rule,
 * 'personal' = default rule for personal folders. It is created in InstallDefaults.
 */
class Version1001Date20260928000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('folder_retention_rules');
		if ($table->hasColumn('target')) {
			return null;
		}
		$table->addColumn('target', Types::STRING, ['notnull' => false, 'length' => 16]);
		return $schema;
	}
}
