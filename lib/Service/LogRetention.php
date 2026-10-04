<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Db\LogMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the app's own records small and free of data that is no longer needed: log entries
 * expire after Settings::logRetentionDays(), "first seen" entries go with their file, and an
 * account's entries go with the account.
 */
class LogRetention {
	public function __construct(
		private LogMapper $logMapper,
		private FirstSeen $firstSeen,
		private Settings $settings,
		private IDBConnection $db,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Once per completed cycle (RetentionJob). Errors only end up in the Nextcloud log – the
	 * next cycle tries again.
	 *
	 * @return array{log: int, seen: int} number of removed entries
	 */
	public function purge(): array {
		$out = ['log' => 0, 'seen' => 0];
		$cutoff = $this->time->getTime() - $this->settings->logRetentionDays() * 86400;
		try {
			$out['log'] = $this->logMapper->purgeOlderThan($cutoff);
		} catch (Throwable $e) {
			$this->logger->error('folder_retention: cleaning up the log failed', ['exception' => $e]);
		}
		try {
			$out['seen'] = $this->firstSeen->purgeOrphans();
		} catch (Throwable $e) {
			$this->logger->error('folder_retention: cleaning up "first seen" failed', ['exception' => $e]);
		}
		if ($out['log'] > 0 || $out['seen'] > 0) {
			$this->logger->info('folder_retention: removed ' . $out['log'] . ' log entries older than ' . $this->settings->logRetentionDays() . ' days and ' . $out['seen'] . ' "first seen" entries of files that no longer exist');
		}
		return $out;
	}

	/**
	 * Before an account is deleted: its log entries (home storage) and its workspace marking.
	 * Entries in team folders stay – they belong to the team folder, not to the account.
	 */
	public function forgetUser(string $uid): void {
		try {
			$storageId = $this->homeStorageId($uid);
			if ($storageId !== null) {
				$this->logMapper->deleteByStorage($storageId);
			}
		} catch (Throwable $e) {
			$this->logger->error('folder_retention: log entries of deleted account "' . $uid . '" could not be removed', ['exception' => $e]);
		}
		try {
			if ($this->settings->isWorkspaceAccount($uid)) {
				$this->settings->setWorkspaceAccount($uid, false);
			}
		} catch (Throwable $e) {
			$this->logger->error('folder_retention: workspace marking of deleted account "' . $uid . '" could not be removed', ['exception' => $e]);
		}
	}

	/**
	 * Numeric ID of the account's home storage, by the storage IDs Nextcloud gives homes (local
	 * data directory or object store as primary storage; IDs over 64 characters are stored as
	 * md5). Deliberately not via getUserFolder(): that would set up a home that never existed.
	 */
	private function homeStorageId(string $uid): ?int {
		$ids = [];
		foreach (['home::' . $uid, 'object::user:' . $uid] as $id) {
			$ids[] = strlen($id) > 64 ? md5($id) : $id;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('numeric_id')->from('storages')
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false ? null : (int)$id;
	}
}
