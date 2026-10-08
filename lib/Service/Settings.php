<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use DateTimeZone;
use OCA\FolderRetention\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Central place for app settings (IAppConfig, app ID folder_retention).
 *
 * Changeable via occ, e.g.:
 *   occ config:app:set folder_retention simulation_mode --value=0 --type=boolean
 */
class Settings {
	private const CURSOR = 'job_cursor';
	private const LAST_CYCLE = 'last_cycle_completed';
	private const TIME_BUDGET = 'job_time_budget';
	private const BATCH_SIZE = 'job_batch_size';
	private const WORKSPACE_ACCOUNTS = 'workspace_accounts';
	private const BLOCKED_ROOTS = 'blocked_roots';
	private const SEEN_MAX_FILEID = 'seen_max_fileid';
	/** Predecessor of the boundary in the early 0.8.0 state (fb4395c): timestamp instead of file ID */
	private const LEGACY_SEEN_SINCE = 'seen_since';
	private const LOCK_TABLE = 'folder_retention_lock';
	private const BLOCK_TABLE = 'folder_retention_block';
	private const RUN_LOCK = 'run';
	/** Selectable log retention in days, ascending; the settings UI offers exactly these */
	public const LOG_RETENTION_CHOICES = [30, 90, 180, 365, 730, 1825];
	public const LOG_RETENTION_DEFAULT = 365;
	public const DELETION_LIMIT_MAX = 1000000;
	private const CYCLE_DELETED = 'cycle_deleted';
	private const DELETION_HALT = 'deletion_halt';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IDBConnection $db,
	) {
	}

	/** Simulation mode – ON by default unless explicitly switched off */
	public function isSimulation(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, Application::CONFIG_SIMULATION, true);
	}

	/**
	 * Simulation mode, read fresh from the database (oc_appconfig) instead of IAppConfig's
	 * per-process cache: a running occ run or job must see the emergency brake "simulation on"
	 * (UI or occ config:app:set) before its next deletion. When in doubt: ON.
	 */
	public function isSimulationFresh(): bool {
		try {
			$raw = $this->loadSimulationValue();
		} catch (\Throwable) {
			return true;
		}
		if ($raw === null) {
			return true; // never set: default ON
		}
		return !in_array(strtolower(trim($raw)), ['0', 'false', 'no', 'off'], true);
	}

	public function setSimulation(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, Application::CONFIG_SIMULATION, $on);
	}

	/** On installation: simulation mode explicitly ON – but never overwrite a deliberate switch-off */
	public function initSimulation(): void {
		if (!$this->appConfig->hasKey(Application::APP_ID, Application::CONFIG_SIMULATION)) {
			$this->setSimulation(true);
		}
	}

	/**
	 * Old switch "default rule also for personal files" (up to 0.5). Now only evaluated once,
	 * when the default rule for personal folders is created.
	 */
	public function includePersonal(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, Application::CONFIG_INCLUDE_PERSONAL, false);
	}

	public function setIncludePersonal(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, Application::CONFIG_INCLUDE_PERSONAL, $on);
	}

	/** Set informational retention tags on files and folders? Default OFF */
	public function tagsEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, Application::CONFIG_TAGS, false);
	}

	public function setTagsEnabled(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, Application::CONFIG_TAGS, $on);
	}

	/** Show the deletion date in the Files app (badge + sidebar tab)? Default ON */
	public function filesInfoEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, Application::CONFIG_FILES_INFO, true);
	}

	public function setFilesInfoEnabled(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, Application::CONFIG_FILES_INFO, $on);
	}

	/**
	 * Days the log keeps its entries (LogRetention::purge). A value set via occ outside
	 * LOG_RETENTION_CHOICES is clamped into its range – never below the shortest choice.
	 */
	public function logRetentionDays(): int {
		$days = $this->appConfig->getValueInt(Application::APP_ID, Application::CONFIG_LOG_RETENTION, self::LOG_RETENTION_DEFAULT);
		$choices = self::LOG_RETENTION_CHOICES;
		return max($choices[0], min($choices[count($choices) - 1], $days));
	}

	/**
	 * Optional emergency brake: at most this many real deletions per cycle, 0 = no limit (default).
	 * Reaching it halts deletion until an admin resumes it (deletionHalt()).
	 */
	public function deletionLimit(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, Application::CONFIG_DELETION_LIMIT, 0));
	}

	/** @throws \InvalidArgumentException for a negative or absurdly large value */
	public function setDeletionLimit(int $limit): void {
		if ($limit < 0 || $limit > self::DELETION_LIMIT_MAX) {
			throw new \InvalidArgumentException('deletion limit must be between 0 and ' . self::DELETION_LIMIT_MAX);
		}
		$this->appConfig->setValueInt(Application::APP_ID, Application::CONFIG_DELETION_LIMIT, $limit);
	}

	/** Real deletions so far in the current cycle (the job works in chunks across processes) */
	public function cycleDeleted(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, self::CYCLE_DELETED, 0));
	}

	public function setCycleDeleted(int $n): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::CYCLE_DELETED, max(0, $n));
	}

	/**
	 * Set when a run reached the deletion limit: nothing is deleted until an admin resumes.
	 *
	 * @return array{at: int, limit: int}|null
	 */
	public function deletionHalt(): ?array {
		$raw = json_decode($this->appConfig->getValueString(Application::APP_ID, self::DELETION_HALT, ''), true);
		if (!is_array($raw) || !isset($raw['at'], $raw['limit'])) {
			return null;
		}
		return ['at' => (int)$raw['at'], 'limit' => (int)$raw['limit']];
	}

	public function haltDeletion(int $limit, int $at): void {
		$this->appConfig->setValueString(Application::APP_ID, self::DELETION_HALT, json_encode(['at' => $at, 'limit' => $limit]));
	}

	/** Resume after the limit was reached: the count for the current cycle starts anew */
	public function resumeDeletion(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::DELETION_HALT);
		$this->setCycleDeleted(0);
	}

	/** @throws \InvalidArgumentException for a value outside LOG_RETENTION_CHOICES */
	public function setLogRetentionDays(int $days): void {
		if (!in_array($days, self::LOG_RETENTION_CHOICES, true)) {
			throw new \InvalidArgumentException('log retention must be one of ' . implode(', ', self::LOG_RETENTION_CHOICES));
		}
		$this->appConfig->setValueInt(Application::APP_ID, Application::CONFIG_LOG_RETENTION, $days);
	}

	/**
	 * Accounts whose files count as a workspace (functional accounts with shared folders):
	 * the default rule applies to them, regardless of includePersonal().
	 *
	 * @return list<string>
	 */
	public function workspaceAccounts(): array {
		$raw = json_decode($this->appConfig->getValueString(Application::APP_ID, self::WORKSPACE_ACCOUNTS, '[]'), true);
		return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
	}

	public function isWorkspaceAccount(string $uid): bool {
		return in_array($uid, $this->workspaceAccounts(), true);
	}

	public function setWorkspaceAccount(string $uid, bool $on): void {
		$list = array_values(array_diff($this->workspaceAccounts(), [$uid]));
		if ($on) {
			$list[] = $uid;
			sort($list);
		}
		$this->appConfig->setValueString(Application::APP_ID, self::WORKSPACE_ACCOUNTS, json_encode($list));
	}

	/** Seconds per job execution */
	public function timeBudget(): int {
		return max(10, $this->appConfig->getValueInt(Application::APP_ID, self::TIME_BUDGET, 120));
	}

	public function batchSize(): int {
		return max(50, $this->appConfig->getValueInt(Application::APP_ID, self::BATCH_SIZE, 500));
	}

	/** Background jobs mode: cron (system cron), ajax or webcron */
	public function backgroundJobsMode(): string {
		try {
			return $this->appConfig->getValueString('core', 'backgroundjobs_mode', 'ajax');
		} catch (\Throwable) {
			return 'ajax';
		}
	}

	public function timezone(): DateTimeZone {
		try {
			return new DateTimeZone($this->config->getSystemValueString('default_timezone', 'Europe/Berlin'));
		} catch (\Exception) {
			return new DateTimeZone('Europe/Berlin');
		}
	}

	/**
	 * Cursor of the current cycle. Older cursors (0.7.x, 0.8 pre-release with "seen") remain readable.
	 *
	 * @return array{root: string, after: int}|null
	 */
	public function getCursor(): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CURSOR, '');
		if ($raw === '') {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['root'], $data['after'])) {
			return null;
		}
		return ['root' => (string)$data['root'], 'after' => (int)$data['after']];
	}

	public function setCursor(?string $rootKey, int $after = 0): void {
		if ($rootKey === null) {
			$this->appConfig->deleteKey(Application::APP_ID, self::CURSOR);
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, self::CURSOR, json_encode(['root' => $rootKey, 'after' => $after]));
	}

	/**
	 * Re-read the app config from the database. IAppConfig keeps the values per process (on the web
	 * additionally briefly in APCu); a background job that runs long after process start would
	 * otherwise see the cursor and cycle end from before it started.
	 */
	public function refresh(): void {
		try {
			$this->appConfig->clearCache();
		} catch (\Throwable) {
			// older Nextcloud without clearCache – then the per-process state stays
		}
	}

	public function lastCycleCompleted(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::LAST_CYCLE, 0);
	}

	public function setLastCycleCompleted(int $ts): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::LAST_CYCLE, $ts);
	}

	/**
	 * Lock against parallel runs (occ and background job): one row in folder_retention_lock
	 * with an expiry. Atomic: a new lock is created by insert-without-overwrite (primary key),
	 * taken over only by an UPDATE conditioned on "expired or already mine" – the database
	 * lets exactly one process win. If a run crashes, the lock expires on its own after
	 * $ttl seconds.
	 *
	 * @return array{holder: string, token: string, until: int}|null foreign holder, or null = acquired
	 */
	public function acquireRunLease(string $holder, string $token, int $now, int $ttl): ?array {
		$holder = mb_substr($holder, 0, 255);
		for ($i = 0; $i < 3; $i++) {
			$inserted = $this->db->insertIgnoreConflict(self::LOCK_TABLE, [
				'lock_name' => self::RUN_LOCK, 'token' => $token, 'holder' => $holder, 'expires' => $now + $ttl, 'renewals' => 0,
			]);
			if ($inserted === 1) {
				return null;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::LOCK_TABLE)
				->set('token', $qb->createNamedParameter($token))
				->set('holder', $qb->createNamedParameter($holder))
				->set('expires', $qb->createNamedParameter($now + $ttl, IQueryBuilder::PARAM_INT))
				->set('renewals', $qb->createFunction($qb->getColumnName('renewals') . ' + 1'))
				->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::RUN_LOCK)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->lt('expires', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)),
					$qb->expr()->eq('token', $qb->createNamedParameter($token)),
				));
			if ($qb->executeStatement() === 1) {
				return null;
			}
			$current = $this->runLease();
			if ($current !== null) {
				return $current;
			}
			// row vanished between the steps (holder released it) – try again
		}
		return ['holder' => 'unbekannt', 'token' => '', 'until' => $now + $ttl];
	}

	/**
	 * Extend a held lock.
	 *
	 * @return bool false = lock no longer belongs to this run (expired and taken over)
	 */
	public function renewRunLease(string $token, int $now, int $ttl): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::LOCK_TABLE)
			->set('expires', $qb->createNamedParameter($now + $ttl, IQueryBuilder::PARAM_INT))
			->set('renewals', $qb->createFunction($qb->getColumnName('renewals') . ' + 1'))
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::RUN_LOCK)))
			->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		return $qb->executeStatement() === 1;
	}

	public function releaseRunLease(string $token): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::LOCK_TABLE)
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::RUN_LOCK)))
			->andWhere($qb->expr()->eq('token', $qb->createNamedParameter($token)));
		$qb->executeStatement();
	}

	/** @return array{holder: string, token: string, until: int}|null */
	public function runLease(): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('holder', 'token', 'expires')->from(self::LOCK_TABLE)
			->where($qb->expr()->eq('lock_name', $qb->createNamedParameter(self::RUN_LOCK)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row === false) {
			return null;
		}
		return ['holder' => (string)$row['holder'], 'token' => (string)$row['token'], 'until' => (int)$row['expires']];
	}

	/**
	 * Boundary between existing and new files: highest file ID in the file cache at installation or
	 * at the update to 0.8 (InstallDefaults). Files with a higher ID were created afterwards – even
	 * copies, which inherit the original's upload and creation time (Cache::copyFromCache), always
	 * get a new, higher ID. For them, "first seen" counts alongside the upload time;
	 * moving and restoring keep the ID. null = not set yet.
	 */
	public function seenMaxFileId(): ?int {
		$id = $this->appConfig->getValueInt(Application::APP_ID, self::SEEN_MAX_FILEID, 0);
		return $id > 0 ? $id : null;
	}

	/** Set only once – a later value would count new files (copies) as existing files again */
	public function initSeenMaxFileId(int $maxFileId): void {
		if ($this->seenMaxFileId() === null) {
			// 0 for an empty file cache: then every file counts as new – smallest valid value is 1
			$this->appConfig->setValueInt(Application::APP_ID, self::SEEN_MAX_FILEID, max(1, $maxFileId));
		}
	}

	/**
	 * Boundary of the early 0.8.0 state (fb4395c): end of the first complete cycle. There a file
	 * counted as new if its "first seen" lay after it. Now only read for the switch to
	 * seen_max_fileid (InstallDefaults). null = not set.
	 */
	public function legacySeenSince(): ?int {
		$ts = $this->appConfig->getValueInt(Application::APP_ID, self::LEGACY_SEEN_SINCE, 0);
		return $ts > 0 ? $ts : null;
	}

	/** Only remove once seen_max_fileid has been derived from it */
	public function dropLegacySeenSince(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::LEGACY_SEEN_SINCE);
	}

	/**
	 * Scopes in which, after a permanent deletion (trash bin bypassed), nothing more is
	 * deleted until an admin lifts the block.
	 *
	 * One row per scope in folder_retention_block, read fresh from the database every time –
	 * not in the app config: its per-process cache (plus APCu on the web) would let a run that
	 * started before a block was set keep deleting in the blocked scope, and read-modify-write
	 * of a shared list would lose blocks set by other processes.
	 *
	 * @return array<string, array{label: string, reason: string, at: int}> scope key → reason
	 */
	public function blockedRoots(): array {
		$this->migrateLegacyBlocks();
		$out = [];
		foreach ($this->loadBlocks() as $row) {
			$out[$row['key']] = ['label' => $row['label'], 'reason' => $row['reason'], 'at' => $row['at']];
		}
		return $out;
	}

	/**
	 * $rootKey = RetentionRoot::blockKey() (storage + root, without kind). Older entries still carry
	 * the kind as a prefix ("home:…", "workspace:…") – they apply to the same scope.
	 */
	public function isRootBlocked(string $rootKey): bool {
		foreach (array_keys($this->blockedRoots()) as $key) {
			if ($key === $rootKey || str_ends_with($key, ':' . $rootKey)) {
				return true;
			}
		}
		return false;
	}

	/** Set a block; an existing block for the scope stays with its reason and timestamp */
	public function blockRoot(string $rootKey, string $label, string $reason, int $at): void {
		$this->insertBlock($rootKey, mb_substr($label, 0, 255), mb_substr($reason, 0, 500), $at);
	}

	/**
	 * Lift only the named blocks – all others (including ones added in the meantime)
	 * stay. With a timestamp ($at) only if the block is still exactly the one displayed: if it was
	 * lifted and set again in the meantime, the new one stays. One conditional DELETE per
	 * block – nothing is written back from an older state.
	 *
	 * @param array<string, ?int> $keys scope key → displayed timestamp (null = any)
	 * @return list<string> keys actually lifted
	 */
	public function unblockRoots(array $keys): array {
		$this->migrateLegacyBlocks();
		$removed = [];
		foreach ($keys as $key => $at) {
			$key = (string)$key;
			if ($this->deleteBlock($key, $at) > 0) {
				$removed[] = $key;
			}
		}
		return $removed;
	}

	/**
	 * In the 0.8.0 pre-release the blocks were stored as JSON in the app config (blocked_roots). Moves them into the
	 * table – on update (InstallDefaults) and, as a precaution, on every access, in case a still
	 * running old process wrote a block there. Read fresh before migrating:
	 * a stale per-process state would otherwise re-set blocks that were lifted long ago.
	 */
	public function migrateLegacyBlocks(): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::BLOCKED_ROOTS, '') === '') {
			return;
		}
		$this->refresh();
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::BLOCKED_ROOTS, '');
		if ($raw === '') {
			return;
		}
		$list = json_decode($raw, true);
		foreach (is_array($list) ? $list : [] as $key => $entry) {
			if (is_array($entry)) {
				$this->blockRoot((string)$key, (string)($entry['label'] ?? $key), (string)($entry['reason'] ?? ''), (int)($entry['at'] ?? 0));
			}
		}
		$this->appConfig->deleteKey(Application::APP_ID, self::BLOCKED_ROOTS);
	}

	// Database access, encapsulated (replaced in unit tests)

	/** Raw value of simulation_mode in oc_appconfig; null = no entry */
	protected function loadSimulationValue(): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(Application::CONFIG_SIMULATION)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false || $value === null ? null : (string)$value;
	}

	/** @return list<array{key: string, label: string, reason: string, at: int}> */
	protected function loadBlocks(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('block_key', 'label', 'reason', 'blocked_at')->from(self::BLOCK_TABLE)
			->orderBy('blocked_at')->addOrderBy('block_key');
		$result = $qb->executeQuery();
		$out = [];
		while (($row = $result->fetch()) !== false) {
			$out[] = ['key' => (string)$row['block_key'], 'label' => (string)$row['label'], 'reason' => (string)($row['reason'] ?? ''), 'at' => (int)$row['blocked_at']];
		}
		$result->closeCursor();
		return $out;
	}

	/** Insert without overwriting (primary key block_key) – atomic, even with parallel runs */
	protected function insertBlock(string $key, string $label, string $reason, int $at): void {
		$this->db->insertIgnoreConflict(self::BLOCK_TABLE, [
			'block_key' => $key, 'label' => $label, 'reason' => $reason, 'blocked_at' => $at,
		]);
	}

	/** @return int number of deleted rows */
	protected function deleteBlock(string $key, ?int $at): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::BLOCK_TABLE)
			->where($qb->expr()->eq('block_key', $qb->createNamedParameter($key)));
		if ($at !== null) {
			$qb->andWhere($qb->expr()->eq('blocked_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT)));
		}
		return $qb->executeStatement();
	}
}
