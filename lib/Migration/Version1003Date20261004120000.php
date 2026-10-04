<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Sicherheitssperren je Bereich (nach einer endgültigen Löschung): eine Zeile je Bereich statt
 * einer JSON-Liste in der App-Config. Gesetzt per Einfügen ohne Überschreiben, aufgehoben per
 * DELETE mit Bedingung, gelesen immer frisch – die App-Config hält Werte je Prozess, ein lange
 * laufender Cron sähe sonst eine neue Sperre nicht. Alte Einträge (blocked_roots) übernimmt
 * InstallDefaults bzw. Settings::migrateLegacyBlocks.
 */
class Version1003Date20261004120000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('folder_retention_block')) {
			return null;
		}
		$table = $schema->createTable('folder_retention_block');
		// RetentionRoot::blockKey() bzw. ältere Form mit Art davor („workspace:…“)
		$table->addColumn('block_key', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('reason', Types::TEXT, ['notnull' => false]);
		$table->addColumn('blocked_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['block_key'], 'fret_block_pk');
		return $schema;
	}
}
