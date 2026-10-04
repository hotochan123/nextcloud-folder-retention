<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Zweite Standardregel für persönliche Ordner: Spalte „target“ unterscheidet die beiden
 * Standardregeln (folder_id = NULL). NULL = allgemeine Standardregel bzw. Ordnerregel,
 * 'personal' = Standardregel für persönliche Ordner. Angelegt wird sie in InstallDefaults.
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
