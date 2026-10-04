<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the rule and audit tables.
 *
 * Table names without the "oc_" prefix; max. 27 characters (Oracle limit of NC migrations).
 */
class Version1000Date20260925000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('folder_retention_rules')) {
			$table = $schema->createTable('folder_retention_rules');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			// NULL = default rule (exactly one, enforced in the service – a UNIQUE index
			// does not cover NULL in PostgreSQL/MySQL).
			$table->addColumn('folder_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			// NULL when period_unit = 'never'
			$table->addColumn('period_value', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('period_unit', Types::STRING, ['notnull' => true, 'length' => 8]);
			// NULL for the default rule
			$table->addColumn('scope', Types::STRING, ['notnull' => false, 'length' => 8]);
			$table->addColumn('basis', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'created']);
			// Boolean columns must be nullable in NC migrations.
			$table->addColumn('notify', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['folder_id'], 'fret_rules_folder_uniq');
		}

		if (!$schema->hasTable('folder_retention_log')) {
			$table = $schema->createTable('folder_retention_log');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('storage_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// Path at the time of deletion (human-readable, e.g. "SVZ Workspace - Academy/x.pdf")
			$table->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 4000]);
			// Rule ID + folder ID of the rule (the folder ID stays meaningful if the rule is deleted)
			$table->addColumn('rule_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('rule_folder_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('rule_label', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('reference_date', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// created | upload | mtime – where the reference date comes from
			$table->addColumn('reference_source', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('deleted_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			// real | simulation
			$table->addColumn('mode', Types::STRING, ['notnull' => true, 'length' => 12]);
			// deleted | would_delete | skipped_locked | error
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('message', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['deleted_at'], 'fret_log_time_idx');
			$table->addIndex(['file_id'], 'fret_log_file_idx');
		}

		return $schema;
	}
}
