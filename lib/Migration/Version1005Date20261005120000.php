<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Migration;

use Closure;
use OCA\FolderRetention\Db\LogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A simulated "would delete" stays in the log, but it can stop being true: the rule changed or was
 * removed, the file is no longer due or has been deleted for real since. superseded_at marks such
 * entries (LogMapper::supersede*), the log shows them as a group of their own.
 * Existing entries: those whose rule was changed or removed after the entry are marked right away;
 * everything else follows with the next run.
 */
class Version1005Date20261005120000 extends SimpleMigrationStep {
	public function __construct(
		private LogMapper $logMapper,
		private ITimeFactory $time,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('folder_retention_log')) {
			return null;
		}
		$table = $schema->getTable('folder_retention_log');
		if ($table->hasColumn('superseded_at')) {
			return null;
		}
		$table->addColumn('superseded_at', Types::BIGINT, ['notnull' => false]);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$n = $this->logMapper->supersedeByChangedRules($this->time->getTime());
		if ($n > 0) {
			$output->info('folder_retention: ' . $n . ' simulated log entries marked as superseded (rule changed or removed since)');
		}
	}
}
