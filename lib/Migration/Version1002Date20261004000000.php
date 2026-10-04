<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * "First seen" per file: reference date for basis=created when Nextcloud knows no upload time
 * (occ files:scan, legacy files) or when a copy has inherited the original's upload time.
 * The run records missing files in batches. Rows of deleted files are left in place –
 * they do no harm (file IDs are not reused).
 *
 * Plus the run lock (occ and background job): one row per lock, set via insert
 * or conditional UPDATE – both atomic, unlike a value in the app config.
 */
class Version1002Date20261004000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		if (!$schema->hasTable('folder_retention_seen')) {
			$table = $schema->createTable('folder_retention_seen');
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('first_seen', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['file_id'], 'fret_seen_pk');
			$changed = true;
		}
		if (!$schema->hasTable('folder_retention_lock')) {
			$table = $schema->createTable('folder_retention_lock');
			$table->addColumn('lock_name', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('holder', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('expires', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// Incremented on every renewal – otherwise MySQL reports 0 affected rows when the value is unchanged
			$table->addColumn('renewals', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['lock_name'], 'fret_lock_pk');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
