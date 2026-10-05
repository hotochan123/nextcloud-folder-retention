<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Db\LogEntry;
use OCA\FolderRetention\Db\LogMapper;
use OCA\FolderRetention\Model\Decision;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Model\RuleSet;
use OCA\FolderRetention\Model\RunStats;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks through all areas storage by storage and evaluates every file at its current
 * location. Used by the job (with time budget + cursor), by the occ command
 * (complete) and by the preview (read-only).
 */
class RetentionRunner {
	/** A new cycle starts after this many seconds at the earliest (daily, with some slack) */
	private const CYCLE_INTERVAL = 23 * 3600;
	/** Lock against parallel runs: expires after this many seconds without renewal */
	private const LEASE_TTL = 15 * 60;
	/** ... and is renewed after this many seconds at the latest */
	private const LEASE_RENEW = 60;
	/** Statuses whose identical repetition updates the previous log entry (see log()) */
	private const REPEATABLE = [LogEntry::STATUS_ERROR, LogEntry::STATUS_SKIPPED_LOCKED, LogEntry::STATUS_SKIPPED_CHANGED];

	public function __construct(
		private RootProvider $roots,
		private FileCacheReader $fileCache,
		private Evaluator $evaluator,
		private RuleService $rules,
		private Settings $settings,
		private Deleter $deleter,
		private LogMapper $logMapper,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private TagService $tags,
		private RuleResolver $resolver,
		private FirstSeen $firstSeen,
		private ISecureRandom $random,
		/** UI language (preview, occ) */
		private IL10N $l,
		/** fixed language of stored texts (log, lock reasons) */
		private ContentLanguage $language,
	) {
	}

	/** Tag sync disabled for this run (after an error) */
	private bool $tagsFailed = false;
	/** held run lock: token and when it was last renewed */
	private ?string $leaseToken = null;
	private string $leaseHolder = '';
	private int $leaseRenewedAt = 0;
	/** @var array<string, true> areas whose lock has already been reported in this run */
	private array $blockReported = [];
	/**
	 * @var array<string, true> areas this run has locked – applies even if saving the lock
	 *                          to the database failed
	 */
	private array $blockedInRun = [];
	/** Simulation mode was switched on during the run – only simulate for the rest of the run */
	private bool $simulationSwitched = false;
	/** Settings::deletionLimit() for this run, 0 = none */
	private int $deletionLimit = 0;
	/** real deletions in earlier chunks of the same cycle (job only) */
	private int $deletedBefore = 0;

	/**
	 * Background job: resumes at the cursor, works until the time budget is used up.
	 */
	public function runScheduled(): RunStats {
		$stats = new RunStats();
		if (!$this->isCli()) {
			// AJAX/Webcron: the job would run in an anonymous request to cron.php. Its header
			// X-NC-Skip-Trashbin would make files_trashbin delete permanently, and max_execution_time
			// (often 30 s) might abort between deleting and logging. Do nothing, cursor stays.
			$stats->notCli = true;
			$this->logger->info('folder_retention: background jobs run via AJAX/Webcron – retention runs only with system cron (or occ folder_retention:run)');
			return $stats;
		}
		$now = $this->time->getTime();
		$cursor = $this->settings->getCursor();

		if ($cursor === null) {
			if ($now - $this->settings->lastCycleCompleted() < self::CYCLE_INTERVAL) {
				$stats->notDue = true;
				$stats->completed = true;
				return $stats;
			}
			$cursor = ['root' => '', 'after' => 0];
		}

		$lockedBy = $this->acquireLease('background job');
		if ($lockedBy !== null) {
			$stats->lockedBy = $lockedBy;
			$this->logger->info('folder_retention: job skipped, already running: ' . $lockedBy);
			return $stats;
		}
		try {
			// Re-read fresh while holding the lock: the process (cron.php) may have started long
			// before this job; another run may have finished the cycle in the meantime
			$this->settings->refresh();
			$cursor = $this->settings->getCursor();
			if ($cursor === null) {
				if ($now - $this->settings->lastCycleCompleted() < self::CYCLE_INTERVAL) {
					$stats->notDue = true;
					$stats->completed = true;
					return $stats;
				}
				$cursor = ['root' => '', 'after' => 0];
				$this->settings->setCycleDeleted(0);
			}
			$this->deletionLimit = $this->settings->deletionLimit();
			$this->deletedBefore = $this->settings->cycleDeleted();
			$this->ensureSeenMark();
			$deadline = microtime(true) + $this->settings->timeBudget();
			$ruleSet = $this->rules->snapshot();
			$simulate = $this->settings->isSimulation();
			$handler = fn (RetentionRoot $root, Decision $d) => $this->act($root, $d, $ruleSet, $simulate, false, $now, $stats);
			$withTags = $this->settings->tagsEnabled();

			foreach ($this->roots->getRoots() as $root) {
				if (strcmp($root->key(), $cursor['root']) < 0) {
					continue;
				}
				$after = $root->key() === $cursor['root'] ? $cursor['after'] : 0;
				$stoppedAt = $this->scanRoot($root, $root->rootPath, $after, $ruleSet, $deadline, $handler, $stats, $withTags, true);
				if ($stoppedAt !== null) {
					$this->settings->setCursor($root->key(), $stoppedAt);
					$this->settings->setCycleDeleted($this->deletedBefore + $stats->deleted);
					$this->logger->info('folder_retention: run interrupted – ' . $stats->summary($this->language->english()));
					return $stats;
				}
			}

			$this->settings->setCursor(null);
			$this->settings->setCycleDeleted(0);
			$this->settings->setLastCycleCompleted($now);
			if ($withTags) {
				$this->sweepTags($stats);
			}
			$stats->completed = true;
			$this->logger->info('folder_retention: cycle completed – ' . $stats->summary($this->language->english()));
			return $stats;
		} catch (RunLockLostException $e) {
			// Cursor stays where it was – the run holding the lock carries on
			return $this->lockLost($stats, $e);
		} finally {
			$this->finishRun($stats);
		}
	}

	/**
	 * Complete run for occ, without cursor and time budget.
	 *
	 * @param bool $dryRun output only – neither delete nor write to the log
	 * @param int|null $ruleId only files to which this rule applies
	 * @param callable(RetentionRoot, Decision, string, ?string):void|null $report per due file (status, message)
	 */
	public function runFull(bool $dryRun, ?int $ruleId, ?callable $report = null): RunStats {
		$stats = new RunStats();
		$now = $this->time->getTime();
		$ruleSet = $this->rules->snapshot();
		$simulate = $dryRun || $this->settings->isSimulation();
		$this->deletionLimit = $this->settings->deletionLimit();
		$this->deletedBefore = 0;

		$handler = function (RetentionRoot $root, Decision $d) use ($ruleSet, $simulate, $dryRun, $ruleId, $now, $stats, $report) {
			if ($ruleId !== null && $d->resolution->rule->id !== $ruleId) {
				return;
			}
			[$status, $message] = $this->act($root, $d, $ruleSet, $simulate, $dryRun, $now, $stats);
			if ($status !== null && $report !== null) {
				$report($root, $d, $status, $message);
			}
		};

		// A dry run writes nothing – it needs no lock and may run alongside another run
		if (!$dryRun) {
			$lockedBy = $this->acquireLease('occ folder_retention:run');
			if ($lockedBy !== null) {
				$stats->lockedBy = $lockedBy;
				return $stats;
			}
		}
		try {
			if (!$dryRun) {
				$this->ensureSeenMark();
			}
			// Tags only on real complete runs – a --rule run does not see all files
			$withTags = !$dryRun && $ruleId === null && $this->settings->tagsEnabled();
			foreach ($this->roots->getRoots() as $root) {
				$this->scanRoot($root, $root->rootPath, 0, $ruleSet, null, $handler, $stats, $withTags, !$dryRun);
			}
			if ($withTags) {
				$this->sweepTags($stats);
			}
			$stats->completed = true;
			return $stats;
		} catch (RunLockLostException $e) {
			return $this->lockLost($stats, $e);
		} finally {
			$this->finishRun($stats);
		}
	}

	/**
	 * The existing/new boundary (Settings::seenMaxFileId) is normally set by InstallDefaults on
	 * install or update. If it is missing anyway, set it now at the latest – until then, to be
	 * safe, every file counts as new.
	 */
	private function ensureSeenMark(): void {
		if ($this->settings->seenMaxFileId() === null) {
			$this->settings->initSeenMaxFileId($this->fileCache->maxFileId());
		}
	}

	/** System cron or occ – not cron.php on the web (AJAX/Webcron) */
	protected function isCli(): bool {
		return PHP_SAPI === 'cli';
	}

	/**
	 * Acquire the run lock. The holder text appears in the other run's message.
	 *
	 * @return string|null description of the other holder, or null = lock acquired
	 */
	private function acquireLease(string $what): ?string {
		$token = $this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
		$holder = $what . ' (PID ' . getmypid() . ', ' . gethostname() . ')';
		$now = $this->time->getTime();
		$other = $this->settings->acquireRunLease($holder, $token, $now, self::LEASE_TTL);
		if ($other !== null) {
			return $this->l->t('%1$s, locked until %2$s UTC', [$other['holder'], date('Y-m-d H:i:s', $other['until'])]);
		}
		$this->leaseToken = $token;
		$this->leaseHolder = $holder;
		$this->leaseRenewedAt = $now;
		return null;
	}

	/**
	 * Renew the held lock if it is older than LEASE_RENEW (long occ runs) –
	 * always with $force, e.g. immediately before deleting.
	 *
	 * @throws RunLockLostException lock has expired and now belongs to another run
	 */
	private function renewLease(bool $force = false): void {
		$now = $this->time->getTime();
		if ($this->leaseToken === null || (!$force && $now - $this->leaseRenewedAt < self::LEASE_RENEW)) {
			return;
		}
		if (!$this->settings->renewRunLease($this->leaseToken, $now, self::LEASE_TTL)) {
			$other = $this->settings->runLease();
			$this->leaseToken = null; // no longer ours – do not release during cleanup
			throw new RunLockLostException($other['holder'] ?? null);
		}
		$this->leaseRenewedAt = $now;
	}

	private function lockLost(RunStats $stats, RunLockLostException $e): RunStats {
		$this->logger->warning('folder_retention: ' . $e->getMessage() . ' – run aborted so that two runs never delete in parallel. ' . $stats->summary($this->language->english()));
		$stats->lockedBy = $e->holder !== null
			? $this->l->t('Run lock lost during the run, now held by %s – this run was aborted', [$e->holder])
			: $this->l->t('Run lock lost during the run – this run was aborted');
		return $stats;
	}

	/**
	 * After every run: re-check trash bin entries of the last seconds (still under the
	 * run lock), restore the filesystem context, release the lock
	 */
	private function finishRun(RunStats $stats): void {
		try {
			$this->deleter->verifyRecentTrash();
			$this->recordLost($stats);
		} catch (Throwable $e) {
			$this->logger->error('folder_retention: trash bin verification at the end of the run failed', ['exception' => $e]);
		}
		$this->deleter->releaseContext();
		if ($this->leaseToken !== null) {
			try {
				$this->settings->releaseRunLease($this->leaseToken);
			} catch (Throwable $e) {
				$this->logger->warning('folder_retention: run lock could not be released (it expires by itself)', ['exception' => $e]);
			}
			$this->leaseToken = null;
		}
		$this->blockReported = [];
		$this->blockedInRun = [];
		$this->simulationSwitched = false;
	}

	/**
	 * Only sync tags (after rule changes, via occ). Deletes and logs nothing.
	 *
	 * @param int|null $folderId only this folder including its subtree; null = everything
	 */
	public function syncTags(?int $folderId): RunStats {
		$stats = new RunStats();
		$ruleSet = $this->rules->snapshot();
		$noop = static function (): void {
		};

		if ($folderId === null) {
			foreach ($this->roots->getRoots() as $root) {
				$this->scanRoot($root, $root->rootPath, 0, $ruleSet, null, $noop, $stats, true);
			}
			$this->sweepTags($stats);
		} else {
			$located = $this->roots->locate($folderId);
			$entry = $this->fileCache->getEntry($folderId);
			if ($located !== null && $entry !== null && $entry['isFolder']) {
				$this->scanRoot($located[0], $entry['path'], 0, $ruleSet, null, $noop, $stats, true);
			}
		}
		$stats->completed = !$this->tagsFailed;
		return $stats;
	}

	/**
	 * Tag ID an object should carry – or null if no rule applies to it.
	 *
	 * @param list<int> $folderChain for files starting at the parent folder, for folders starting at the folder itself
	 */
	public function desiredTag(RetentionRoot $root, array $folderChain, RuleSet $ruleSet): ?int {
		$ruleSet = $ruleSet->forRoot($root);
		$resolution = $this->resolver->resolve($folderChain, $ruleSet->byFolderId, $ruleSet->default);
		if ($resolution->isDefault() && $root->isHome() && $ruleSet->personal === null) {
			return null; // without a default rule for personal folders nothing is deleted there
		}
		return $this->tags->tagIdFor($resolution->rule->period);
	}

	/**
	 * @param array<int, ?int> $tags
	 * @param callable():?int $compute
	 */
	private function want(array &$tags, int $fileId, callable $compute): void {
		if ($this->tagsFailed) {
			return;
		}
		try {
			$tags[$fileId] = $compute();
		} catch (\Throwable $e) {
			$this->tagsFailed = true;
			$this->logger->error('folder_retention: could not determine tag, tag sync disabled for this run', ['exception' => $e]);
		}
	}

	/** @param array<int, ?int> $desired gets emptied */
	private function flushTags(array &$desired, RunStats $stats): void {
		if ($desired === [] || $this->tagsFailed) {
			$desired = [];
			return;
		}
		try {
			$this->tags->apply($desired, $stats);
		} catch (\Throwable $e) {
			// Tags are display only – an error must not stop the retention run
			$this->tagsFailed = true;
			$this->logger->error('folder_retention: tag sync failed, disabled for this run', ['exception' => $e]);
		}
		$desired = [];
	}

	private function sweepTags(RunStats $stats): void {
		if ($this->tagsFailed) {
			return;
		}
		try {
			$stats->tagsRemoved += $this->tags->sweepOrphans();
		} catch (\Throwable $e) {
			$this->logger->error('folder_retention: cleaning up orphaned tags failed', ['exception' => $e]);
		}
	}

	/**
	 * Files that would be deleted within the next $days days (including those already due).
	 *
	 * @param int|null $folderId null = all files to which a default rule applies
	 * @param bool $personal with $folderId = null: the default rule for personal folders instead of the general one
	 * @return array{items: list<array<string, mixed>>, total: int, truncated: bool, incomplete: bool}
	 */
	public function preview(?int $folderId, int $days, int $limit, float $budgetSeconds, bool $personal = false): array {
		$now = $this->time->getTime();
		$until = $now + $days * 86400;
		$ruleSet = $this->rules->snapshot();
		$deadline = microtime(true) + $budgetSeconds;
		$stats = new RunStats();
		$items = [];
		$total = 0;

		if ($folderId === null) {
			$roots = array_filter($this->roots->getRoots(), fn (RetentionRoot $r) => $r->isHome() === $personal);
			$targets = array_map(fn (RetentionRoot $r) => [$r, $r->rootPath], array_values($roots));
		} else {
			$located = $this->roots->locate($folderId);
			$entry = $this->fileCache->getEntry($folderId);
			if ($located === null || $entry === null) {
				return ['items' => [], 'total' => 0, 'truncated' => false, 'incomplete' => false];
			}
			$targets = [[$located[0], $entry['path']]];
		}

		$handler = function (RetentionRoot $root, Decision $d) use ($folderId, $until, $limit, $now, &$items, &$total) {
			if ($folderId === null && !$d->resolution->isDefault()) {
				return;
			}
			if ($d->expiresAt === null || $d->expiresAt > $until) {
				return;
			}
			$total++;
			if (count($items) < $limit) {
				$items[] = [
					'fileId' => $d->file->fileId,
					'path' => $root->displayPath($d->file->path),
					'size' => $d->file->size,
					'ruleId' => $d->resolution->rule->id,
					'ruleFolderId' => $d->resolution->sourceFolderId(),
					'ruleLabel' => $d->resolution->rule->period->label($this->l),
					// Where the rule comes from – resolved to a name below
					'ruleSource' => $d->resolution->isDefault()
						? ($d->resolution->rule->personal ? $this->l->t('Default rule for personal folders') : $this->l->t('Default rule'))
						: ($d->resolution->sourceFolderId() === $root->rootId ? $this->l->t('“%s”', [$root->label]) : null),
					'basis' => $d->resolution->rule->basis->value,
					'referenceDate' => $d->reference?->timestamp,
					'referenceSource' => $d->reference?->source,
					'expiresAt' => $d->expiresAt,
					'overdue' => $d->expiresAt <= $now,
				];
			}
		};

		$incomplete = false;
		foreach ($targets as [$root, $path]) {
			if ($this->scanRoot($root, $path, 0, $ruleSet, $deadline, $handler, $stats) !== null) {
				$incomplete = true;
				break;
			}
		}
		usort($items, fn ($a, $b) => $a['expiresAt'] <=> $b['expiresAt']);
		$names = $this->fileCache->getNames(array_values(array_filter(array_map(
			fn ($i) => $i['ruleSource'] === null ? $i['ruleFolderId'] : null, $items))));
		foreach ($items as &$item) {
			$item['ruleSource'] ??= $this->l->t('“%s”', [$names[$item['ruleFolderId']] ?? '#' . $item['ruleFolderId']]);
		}
		unset($item);
		return ['items' => $items, 'total' => $total, 'truncated' => $total > count($items), 'incomplete' => $incomplete];
	}

	/**
	 * @param callable(RetentionRoot, Decision):mixed $handler
	 * @param bool $recordSeen write missing "first seen" to the DB (only real runs/simulation)
	 * @return int|null last processed fileid if the time budget is used up; null = done
	 */
	private function scanRoot(RetentionRoot $root, string $pathPrefix, int $after, RuleSet $ruleSet, ?float $deadline, callable $handler, RunStats $stats, bool $withTags = false, bool $recordSeen = false): ?int {
		$this->fileCache->reset();
		$tz = $this->settings->timezone();
		$now = $this->time->getTime();
		$ruleSet = $ruleSet->forRoot($root);
		$defaultApplies = !$root->isHome() || $ruleSet->personal !== null;
		$batchSize = $this->settings->batchSize();
		// unknown boundary: to be safe, treat every file as new (0)
		$seenMark = $this->settings->seenMaxFileId() ?? 0;
		$withTags = $withTags && !$this->tagsFailed;
		/** @var array<int, ?int> $tags fileid → desired tag ID */
		$tags = [];

		if ($withTags && $after === 0) {
			// The start folder itself is not under $pathPrefix/...
			$startId = $pathPrefix === $root->rootPath ? $root->rootId : $this->fileCache->getIdByPath($root->storageId, $pathPrefix);
			$chain = $startId === null ? null : $this->fileCache->chain($startId, $root->rootId);
			if ($chain !== null) {
				$this->want($tags, $startId, fn () => $this->desiredTag($root, $chain, $ruleSet));
			}
		}

		while (true) {
			$batch = $this->fileCache->fetchFiles($root->storageId, $pathPrefix, $after, $batchSize, $withTags);
			if ($batch === []) {
				$this->flushTags($tags, $stats);
				return null;
			}
			$this->fileCache->prefetch(array_map(fn ($f) => $f->isFolder ? $f->fileId : $f->parentId, $batch));
			$batch = $this->enrich($batch, $now, $recordSeen, $seenMark);
			$this->renewLease();

			foreach ($batch as $file) {
				$after = $file->fileId;
				if ($file->isFolder) {
					// Folders carry the retention period that applies to files directly inside them
					$chain = $this->fileCache->chain($file->fileId, $root->rootId);
					if ($chain !== null && $withTags) {
						$this->want($tags, $file->fileId, fn () => $this->desiredTag($root, $chain, $ruleSet));
					}
				} else {
					$chain = $this->fileCache->chain($file->parentId, $root->rootId);
					if ($chain === null) {
						continue; // not (or no longer) under the area root
					}
					$stats->evaluated++;
					$decision = $this->evaluator->evaluate($file, $chain, $ruleSet, $defaultApplies, $tz, $now);
					$handler($root, $decision);
					if ($withTags) {
						$this->want($tags, $file->fileId, fn () => $decision->skipReason === Decision::SKIP_PERSONAL
							? null : $this->tags->tagIdFor($decision->resolution->rule->period));
					}
				}

				if ($deadline !== null && microtime(true) >= $deadline) {
					$this->flushTags($tags, $stats);
					return $after;
				}
			}
			$this->flushTags($tags, $stats);
		}
	}

	/**
	 * Enriches a batch with "first seen" and the last real deletion per file.
	 * Every file without an entry counts as seen from now on – even with an upload time, because
	 * copies inherit the original's (ReferenceDate). Without an upload time it is thus not due.
	 * Restored files (last deleted by the app, not seen since) likewise count as seen from now
	 * on – the retention period restarts from the restore (ReferenceDate).
	 * Only recorded with $record; preview and dry run calculate with "now".
	 *
	 * @param list<FileRow> $batch
	 * @return list<FileRow>
	 */
	private function enrich(array $batch, int $now, bool $record, int $seenMark): array {
		$files = array_values(array_filter($batch, fn (FileRow $f) => !$f->isFolder));
		if ($files === []) {
			return $batch;
		}
		$lastDeleted = $this->logMapper->lastDeleted(array_map(fn (FileRow $f) => $f->fileId, $files));
		$unseen = [];
		$restored = [];
		foreach ($files as $f) {
			$deletedAt = $lastDeleted[$f->fileId] ?? null;
			if ($deletedAt !== null && ($f->firstSeen === null || $f->firstSeen <= $deletedAt)) {
				$restored[$f->fileId] = true;
			} elseif ($f->firstSeen === null) {
				$unseen[$f->fileId] = true;
			}
		}
		if ($record && $unseen !== []) {
			$this->firstSeen->record(array_keys($unseen), $now);
		}
		if ($record && $restored !== []) {
			$this->firstSeen->recordRestored(array_keys($restored), $now);
		}
		return array_map(fn (FileRow $f) => $f->isFolder ? $f : $f->with(
			isset($unseen[$f->fileId]) || isset($restored[$f->fileId]) ? $now : $f->firstSeen,
			$lastDeleted[$f->fileId] ?? null,
			$seenMark,
		), $batch);
	}

	/**
	 * Immediately before deleting: location, parent chain, rules and reference date fresh from the DB.
	 * The scan may be hours old (occ without time budget), and the parent chains come from the
	 * cache – moved files or changed rules must not be deleted based on stale state.
	 *
	 * @return array{0: ?Decision, 1: ?string} fresh decision or reason why it is not deleted
	 */
	private function recheck(RetentionRoot $root, Decision $d): array {
		$now = $this->time->getTime();
		$row = $this->fileCache->getFileRow($d->file->fileId);
		if ($row === null) {
			return [null, $this->language->l10n()->t('File no longer exists')];
		}
		if ($row->storageId !== $d->file->storageId || $row->path !== $d->file->path) {
			return [null, $this->language->l10n()->t('File was moved or renamed since it was evaluated')];
		}
		// mtime alone is not enough: clients can keep it on upload (X-OC-MTime), size and etag
		// still reveal new content
		if ($row->mtime !== $d->file->mtime || $row->size !== $d->file->size || $row->etag !== $d->file->etag) {
			return [null, $this->language->l10n()->t('File was modified since it was evaluated')];
		}
		$chain = $this->fileCache->freshChain($row->parentId, $root->rootId);
		if ($chain === null) {
			return [null, $this->language->l10n()->t('File is no longer within the area')];
		}
		$row = $row->with($row->firstSeen ?? $d->file->firstSeen, $this->logMapper->lastDeleted([$row->fileId])[$row->fileId] ?? null, $this->settings->seenMaxFileId() ?? 0);
		$ruleSet = $this->rules->snapshot()->forRoot($root);
		$defaultApplies = !$root->isHome() || $ruleSet->personal !== null;
		$fresh = $this->evaluator->evaluate($row, $chain, $ruleSet, $defaultApplies, $this->settings->timezone(), $now);
		if ($fresh->resolution->rule->id !== $d->resolution->rule->id) {
			return [null, $this->language->l10n()->t('Rule changed since the file was evaluated')];
		}
		if (!$fresh->isDueAt($now)) {
			return [null, $this->language->l10n()->t('No longer due after re-checking')];
		}
		return [$fresh, null];
	}

	/**
	 * Errors on a single file do not abort the run: log them, continue at the cursor.
	 *
	 * @return array{0: ?string, 1: ?string} status (null = not due) and message
	 */
	private function act(RetentionRoot $root, Decision $d, RuleSet $ruleSet, bool $simulate, bool $dryRun, int $now, RunStats $stats): array {
		if (!$d->isDueAt($now)) {
			return [null, null];
		}
		$stats->due++;
		$simulate = $simulate || $this->simulationSwitched;
		try {
			return $this->actDue($root, $d, $simulate, $dryRun, $now, $stats);
		} catch (RunLockLostException $e) {
			throw $e;
		} catch (Throwable $e) {
			$stats->errors++;
			$message = mb_substr($this->language->l10n()->t('Internal error: %1$s: %2$s', [$e::class, $e->getMessage()]), 0, 1000);
			$this->logger->error('folder_retention: error on file ' . $d->file->fileId . ', run continues', ['exception' => $e]);
			if (!$dryRun) {
				try {
					$this->log($root, $d, $simulate ? LogEntry::MODE_SIMULATION : LogEntry::MODE_REAL, LogEntry::STATUS_ERROR, $message, $this->time->getTime());
				} catch (Throwable) {
					// Log itself not reachable – already recorded in the Nextcloud log
				}
			}
			return [LogEntry::STATUS_ERROR, $message];
		}
	}

	/**
	 * @return array{0: string, 1: ?string}
	 */
	private function actDue(RetentionRoot $root, Decision $d, bool $simulate, bool $dryRun, int $now, RunStats $stats): array {
		$rule = $d->resolution->rule;

		if ($dryRun) {
			$stats->simulated++;
			return [LogEntry::STATUS_WOULD_DELETE, null];
		}

		if ($simulate) {
			$stats->simulated++;
			if (!$this->logMapper->hasSimulated($d->file->fileId, $rule->id, $rule->logLabel($this->language->l10n()), $d->reference->timestamp, $d->reference->source)) {
				$this->log($root, $d, LogEntry::MODE_SIMULATION, LogEntry::STATUS_WOULD_DELETE, null, $now);
			}
			return [LogEntry::STATUS_WOULD_DELETE, null];
		}

		if (isset($this->blockedInRun[$root->blockKey()]) || $this->settings->isRootBlocked($root->blockKey())) {
			// After a permanent deletion: nothing more in this area until an admin lifts the lock
			$stats->skipped++;
			$stats->blocked++;
			if (!isset($this->blockReported[$root->blockKey()])) {
				$this->blockReported[$root->blockKey()] = true;
				$this->logger->warning('folder_retention: area "' . $root->label . '" is locked (earlier permanent deletion) – nothing is deleted until the lock is lifted');
			}
			return [LogEntry::STATUS_SKIPPED_BLOCKED, $this->language->l10n()->t('Area locked after a permanent deletion – lift the lock in the settings')];
		}

		$halted = $this->deleter->haltReason();
		if ($halted !== null) {
			// The trash bin threw an exception in this run – Nextcloud might afterwards delete bypassing
			// the trash bin. Rest of the run: touch nothing more (warning is in the Nextcloud log)
			$stats->skipped++;
			$stats->blocked++;
			return [LogEntry::STATUS_SKIPPED_BLOCKED, $this->language->l10n()->t('Deletion halted for this run: %s', [$halted])];
		}

		// Emergency brake: simulation mode switched on mid-run (UI or occ config:app:set).
		// Fresh from the database – IAppConfig holds the value from process start
		if ($this->settings->isSimulationFresh()) {
			if (!$this->simulationSwitched) {
				$this->simulationSwitched = true;
				$this->logger->warning('folder_retention: simulation mode switched on during the run – the rest of the run is only simulated. ' . $stats->summary($this->language->english()));
			}
			return $this->actDue($root, $d, true, false, $now, $stats);
		}

		$limitReached = $this->deletionLimitReached($stats);
		if ($limitReached !== null) {
			$stats->skipped++;
			$stats->blocked++;
			return [LogEntry::STATUS_SKIPPED_BLOCKED, $limitReached];
		}

		// Ensure the lock immediately before deleting – never two runs at the same time
		$this->renewLease(true);
		[$fresh, $reason] = $this->recheck($root, $d);
		if ($fresh === null) {
			$stats->skipped++;
			$this->log($root, $d, LogEntry::MODE_REAL, LogEntry::STATUS_SKIPPED_CHANGED, $reason, $this->time->getTime());
			return [LogEntry::STATUS_SKIPPED_CHANGED, $reason];
		}

		[$status, $message] = $this->deleter->delete($root, $fresh->file);
		match ($status) {
			LogEntry::STATUS_DELETED => $stats->deleted++,
			LogEntry::STATUS_ERROR, LogEntry::STATUS_DELETED_FINAL => $stats->errors++,
			default => $stats->skipped++,
		};
		// Log this file's status first, then lock and correct: an error while locking must not
		// replace "deleted"/"deleted_final" with "Internal error"
		try {
			$this->log($root, $fresh, LogEntry::MODE_REAL, $status, $message, $this->time->getTime());
		} catch (Throwable $e) {
			// The deletion has happened – do not report it as "error on file", but honestly here
			$this->logger->error('folder_retention: file ' . $fresh->file->fileId . ' ("' . $root->displayPath($fresh->file->path) . '") processed with status ' . $status . ', writing the log entry failed', ['exception' => $e]);
		}
		if ($status === LogEntry::STATUS_DELETED_FINAL) {
			$this->block($root, (string)$message);
			$this->logger->error('folder_retention: file in "' . $root->label . '" was deleted permanently instead of being moved to the trash bin – area locked until an admin lifts the lock', [
				'fileId' => $fresh->file->fileId, 'path' => $fresh->file->path,
			]);
		}
		$this->recordLost($stats);
		return [$status, $message];
	}

	/**
	 * Optional emergency brake (Settings::deletionLimit): once a cycle has deleted that many files,
	 * deletion halts – in this and every later run – until an admin resumes it. Guards against a
	 * period set far too short on a large folder or a server clock far in the future.
	 *
	 * @return string|null reason why nothing more is deleted, null = carry on
	 */
	private function deletionLimitReached(RunStats $stats): ?string {
		$halt = $this->settings->deletionHalt();
		if ($halt === null && $this->deletionLimit > 0 && $this->deletedBefore + $stats->deleted >= $this->deletionLimit) {
			$halt = ['at' => $this->time->getTime(), 'limit' => $this->deletionLimit];
			$this->settings->haltDeletion($halt['limit'], $halt['at']);
			$this->logger->warning('folder_retention: deletion limit of ' . $halt['limit'] . ' files per run reached – nothing more is deleted until an admin resumes deletion in the settings. ' . $stats->summary($this->language->english()));
		}
		if ($halt === null) {
			return null;
		}
		return $this->language->l10n()->t('Deletion halted: limit of %d deletions per run reached – resume it in the settings', [$halt['limit']]);
	}

	/**
	 * Lock an area: immediately for this run, plus persistently in the database. If saving fails,
	 * it is recorded in the Nextcloud log; this run still deletes nothing more there.
	 */
	private function block(RetentionRoot $root, string $reason): void {
		$this->blockedInRun[$root->blockKey()] = true;
		try {
			$this->settings->blockRoot($root->blockKey(), $root->label, $reason, $this->time->getTime());
		} catch (Throwable $e) {
			$this->logger->error('folder_retention: lock for area "' . $root->label . '" (' . $root->blockKey() . ') could not be saved – applies to this run only; reason: ' . $reason, ['exception' => $e]);
		}
	}

	/**
	 * Files recorded as deleted in this run whose trash bin entry Nextcloud has since overwritten
	 * (same name in the same second): correct the log, lock the area. The Deleter has already
	 * halted deletion for the rest of the run.
	 */
	private function recordLost(RunStats $stats): void {
		foreach ($this->deleter->takeLost() as [$lostRoot, $lostFile, $reason]) {
			$stats->deleted = max(0, $stats->deleted - 1);
			$stats->errors++;
			$this->block($lostRoot, $reason);
			$this->logger->error('folder_retention: file in "' . $lostRoot->label . '" was deleted permanently (trash bin entry overwritten) – area locked until an admin lifts the lock', [
				'fileId' => $lostFile->fileId, 'path' => $lostFile->path,
			]);
			try {
				if (!$this->logMapper->markDeletedFinal($lostFile->fileId, $reason)) {
					$this->logger->error('folder_retention: log entry for file ' . $lostFile->fileId . ' ("' . $lostRoot->displayPath($lostFile->path) . '") not found – permanent deletion only recorded here');
				}
			} catch (Throwable $e) {
				$this->logger->error('folder_retention: log for file ' . $lostFile->fileId . ' could not be corrected (deleted permanently)', ['exception' => $e]);
			}
		}
	}

	private function log(RetentionRoot $root, Decision $d, string $mode, string $status, ?string $message, int $at): void {
		$rule = $d->resolution->rule;
		$ruleLabel = $rule->logLabel($this->language->l10n());
		// The same error or skip reason every night: move the existing entry to the latest
		// occurrence instead of adding one per run. Deletions always get their own entry.
		if (in_array($status, self::REPEATABLE, true)) {
			$repeat = $this->logMapper->findRepeat($d->file->fileId, $mode, $status, $ruleLabel, $message);
			if ($repeat !== null) {
				$this->logMapper->touch($repeat, $at);
				return;
			}
		}
		$entry = new LogEntry();
		$entry->setFileId($d->file->fileId);
		$entry->setStorageId($d->file->storageId);
		$entry->setPath(mb_substr($root->displayPath($d->file->path), 0, 4000));
		$entry->setRootKey($root->blockKey());
		$entry->setRuleId($rule->id);
		$entry->setRuleFolderId($rule->folderId);
		$entry->setRuleLabel($ruleLabel);
		$entry->setReferenceDate($d->reference->timestamp);
		$entry->setReferenceSource($d->reference->source);
		$entry->setDeletedAt($at);
		$entry->setMode($mode);
		$entry->setStatus($status);
		$entry->setMessage($message);
		$this->logMapper->insert($entry);
	}
}
