<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * „Zuerst gesehen“ je Datei: Bezugsdatum für basis=created, wenn Nextcloud keine Upload-Zeit
 * kennt (occ files:scan, Altbestand) bzw. eine Kopie die Upload-Zeit des Originals geerbt hat.
 * Der Lauf trägt fehlende Dateien gebündelt ein. Zeilen gelöschter Dateien bleiben liegen –
 * sie stören nicht (Datei-IDs werden nicht wiederverwendet).
 *
 * Dazu die Laufsperre (occ und Hintergrundjob): eine Zeile je Sperre, gesetzt per Einfügen
 * bzw. bedingtem UPDATE – beides atomar, anders als ein Wert in der App-Config.
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
			// Zählt jede Verlängerung hoch – sonst meldet MySQL bei unverändertem Wert 0 betroffene Zeilen
			$table->addColumn('renewals', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['lock_name'], 'fret_lock_pk');
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
