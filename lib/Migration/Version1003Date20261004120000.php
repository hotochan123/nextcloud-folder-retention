<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Safety locks per area (after a permanent deletion): one row per area instead of
 * a JSON list in the app config. Set via insert without overwriting, lifted via
 * conditional DELETE, always read fresh – the app config caches values per process, so a long-
 * running cron would otherwise not see a new lock. Old entries (blocked_roots) are taken over by
 * InstallDefaults or Settings::migrateLegacyBlocks.
 */
class Version1003Date20261004120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('folder_retention_block')) {
			return null;
		}
		$table = $schema->createTable('folder_retention_block');
		// RetentionRoot::blockKey() or the older form prefixed with the kind ("workspace:…")
		$table->addColumn('block_key', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
		$table->addColumn('blocked_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['block_key'], 'fret_block_pk');
		return $schema;
	}
}
