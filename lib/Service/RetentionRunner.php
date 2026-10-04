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
 * Durchläuft alle Bereiche Storage für Storage und bewertet jede Datei an ihrem
 * aktuellen Speicherort. Wird vom Job (mit Zeitbudget + Cursor), vom occ-Befehl
 * (komplett) und von der Vorschau (nur lesen) verwendet.
 */
class RetentionRunner {
	/** Frühestens nach so vielen Sekunden beginnt ein neuer Zyklus (täglich, mit etwas Spiel) */
	private const CYCLE_INTERVAL = 23 * 3600;
	/** Sperre gegen parallele Läufe: läuft ohne Verlängerung nach so vielen Sekunden aus */
	private const LEASE_TTL = 15 * 60;
	/** … und wird spätestens nach so vielen Sekunden verlängert */
	private const LEASE_RENEW = 60;

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

	/** Tag-Abgleich für diesen Lauf abgeschaltet (nach einem Fehler) */
	private bool $tagsFailed = false;
	/** gehaltene Laufsperre: Token und wann zuletzt verlängert */
	private ?string $leaseToken = null;
	private string $leaseHolder = '';
	private int $leaseRenewedAt = 0;
	/** @var array<string, true> Bereiche, deren Sperre in diesem Lauf schon gemeldet wurde */
	private array $blockReported = [];
	/**
	 * @var array<string, true> Bereiche, die dieser Lauf gesperrt hat – gilt auch, wenn das
	 *                          Speichern der Sperre in der Datenbank gescheitert ist
	 */
	private array $blockedInRun = [];
	/** Simulation wurde während des Laufs eingeschaltet – Rest des Laufs nur noch simulieren */
	private bool $simulationSwitched = false;

	/**
	 * Hintergrundjob: setzt am Cursor fort, arbeitet bis das Zeitbudget erschöpft ist.
	 */
	public function runScheduled(): RunStats {
		$stats = new RunStats();
		if (!$this->isCli()) {
			// AJAX/Webcron: Der Job liefe in einer anonymen Anfrage an cron.php. Deren Kopfzeile
			// X-NC-Skip-Trashbin ließe files_trashbin endgültig löschen, und max_execution_time (oft
			// 30 s) bräche womöglich zwischen Löschen und Protokoll ab. Nichts tun, Cursor bleibt.
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
			// Mit der Sperre frisch lesen: Der Prozess (cron.php) kann lange vor diesem Job
			// gestartet sein; inzwischen hat ein anderer Lauf womöglich den Zyklus beendet
			$this->settings->refresh();
			$cursor = $this->settings->getCursor();
			if ($cursor === null) {
				if ($now - $this->settings->lastCycleCompleted() < self::CYCLE_INTERVAL) {
					$stats->notDue = true;
					$stats->completed = true;
					return $stats;
				}
				$cursor = ['root' => '', 'after' => 0];
			}
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
					$this->logger->info('folder_retention: run interrupted – ' . $stats->summary($this->language->english()));
					return $stats;
				}
			}

			$this->settings->setCursor(null);
			$this->settings->setLastCycleCompleted($now);
			if ($withTags) {
				$this->sweepTags($stats);
			}
			$stats->completed = true;
			$this->logger->info('folder_retention: cycle completed – ' . $stats->summary($this->language->english()));
			return $stats;
		} catch (RunLockLostException $e) {
			// Cursor bleibt, wo er war – der Lauf, der die Sperre hält, arbeitet weiter
			return $this->lockLost($stats, $e);
		} finally {
			$this->finishRun($stats);
		}
	}

	/**
	 * Kompletter Lauf für occ, ohne Cursor und Zeitbudget.
	 *
	 * @param bool $dryRun nur ausgeben – weder löschen noch ins Log schreiben
	 * @param int|null $ruleId nur Dateien, für die diese Regel gilt
	 * @param callable(RetentionRoot, Decision, string, ?string):void|null $report pro fälliger Datei (Status, Meldung)
	 */
	public function runFull(bool $dryRun, ?int $ruleId, ?callable $report = null): RunStats {
		$stats = new RunStats();
		$now = $this->time->getTime();
		$ruleSet = $this->rules->snapshot();
		$simulate = $dryRun || $this->settings->isSimulation();

		$handler = function (RetentionRoot $root, Decision $d) use ($ruleSet, $simulate, $dryRun, $ruleId, $now, $stats, $report) {
			if ($ruleId !== null && $d->resolution->rule->id !== $ruleId) {
				return;
			}
			[$status, $message] = $this->act($root, $d, $ruleSet, $simulate, $dryRun, $now, $stats);
			if ($status !== null && $report !== null) {
				$report($root, $d, $status, $message);
			}
		};

		// Dry-Run schreibt nichts – er braucht keine Sperre und darf neben einem Lauf stehen
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
			// Tags nur bei echten Komplettläufen – ein --rule-Lauf sieht nicht alle Dateien
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
	 * Grenze Bestand/neu (Settings::seenMaxFileId) setzt eigentlich InstallDefaults bei Installation
	 * bzw. Update. Fehlt sie trotzdem, spätestens jetzt – bis dahin gilt vorsichtig jede Datei als neu.
	 */
	private function ensureSeenMark(): void {
		if ($this->settings->seenMaxFileId() === null) {
			$this->settings->initSeenMaxFileId($this->fileCache->maxFileId());
		}
	}

	/** System-Cron bzw. occ – nicht cron.php im Web (AJAX/Webcron) */
	protected function isCli(): bool {
		return PHP_SAPI === 'cli';
	}

	/**
	 * Laufsperre holen. Holder-Text erscheint beim jeweils anderen Lauf in der Meldung.
	 *
	 * @return string|null Beschreibung des fremden Halters oder null = Sperre erhalten
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
	 * Gehaltene Sperre verlängern, wenn sie älter als LEASE_RENEW ist (lange occ-Läufe) –
	 * mit $force immer, z. B. unmittelbar vor dem Löschen.
	 *
	 * @throws RunLockLostException Sperre ist abgelaufen und gehört inzwischen einem anderen Lauf
	 */
	private function renewLease(bool $force = false): void {
		$now = $this->time->getTime();
		if ($this->leaseToken === null || (!$force && $now - $this->leaseRenewedAt < self::LEASE_RENEW)) {
			return;
		}
		if (!$this->settings->renewRunLease($this->leaseToken, $now, self::LEASE_TTL)) {
			$other = $this->settings->runLease();
			$this->leaseToken = null; // nicht mehr unsere – beim Aufräumen nicht freigeben
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
	 * Nach jedem Lauf: Papierkorb-Einträge der letzten Sekunden nachprüfen (noch unter der
	 * Laufsperre), Dateisystem-Kontext zurück, Sperre freigeben
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
	 * Nur Tags abgleichen (nach Regeländerungen, per occ). Löscht und protokolliert nichts.
	 *
	 * @param int|null $folderId nur dieser Ordner samt Unterbaum; null = alles
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
	 * Tag-ID, die ein Objekt tragen soll – oder null, wenn für es keine Regel greift.
	 *
	 * @param list<int> $folderChain bei Dateien ab dem Elternordner, bei Ordnern ab dem Ordner selbst
	 */
	public function desiredTag(RetentionRoot $root, array $folderChain, RuleSet $ruleSet): ?int {
		$ruleSet = $ruleSet->forRoot($root);
		$resolution = $this->resolver->resolve($folderChain, $ruleSet->byFolderId, $ruleSet->default);
		if ($resolution->isDefault() && $root->isHome() && $ruleSet->personal === null) {
			return null; // ohne persönliche Standardregel wird dort nichts gelöscht
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

	/** @param array<int, ?int> $desired wird geleert */
	private function flushTags(array &$desired, RunStats $stats): void {
		if ($desired === [] || $this->tagsFailed) {
			$desired = [];
			return;
		}
		try {
			$this->tags->apply($desired, $stats);
		} catch (\Throwable $e) {
			// Tags sind nur Anzeige – ein Fehler darf den Aufbewahrungslauf nicht stoppen
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
	 * Dateien, die in den nächsten $days Tagen gelöscht würden (bereits fällige eingeschlossen).
	 *
	 * @param int|null $folderId null = alle Dateien, für die eine Standardregel gilt
	 * @param bool $personal bei $folderId = null: die Standardregel persönlicher Ordner statt der allgemeinen
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
					// Woher die Regel kommt – wird unten zu einem Namen aufgelöst
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
	 * @param bool $recordSeen fehlendes „zuerst gesehen“ in die DB schreiben (nur echte Läufe/Simulation)
	 * @return int|null letzte bearbeitete fileid, wenn das Zeitbudget erschöpft ist; null = fertig
	 */
	private function scanRoot(RetentionRoot $root, string $pathPrefix, int $after, RuleSet $ruleSet, ?float $deadline, callable $handler, RunStats $stats, bool $withTags = false, bool $recordSeen = false): ?int {
		$this->fileCache->reset();
		$tz = $this->settings->timezone();
		$now = $this->time->getTime();
		$ruleSet = $ruleSet->forRoot($root);
		$defaultApplies = !$root->isHome() || $ruleSet->personal !== null;
		$batchSize = $this->settings->batchSize();
		// unbekannte Grenze: vorsichtig jede Datei als neu behandeln (0)
		$seenMark = $this->settings->seenMaxFileId() ?? 0;
		$withTags = $withTags && !$this->tagsFailed;
		/** @var array<int, ?int> $tags fileid → gewünschte Tag-ID */
		$tags = [];

		if ($withTags && $after === 0) {
			// Der Startordner selbst liegt nicht unter $pathPrefix/…
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
					// Ordner tragen die Frist, die für Dateien direkt darin gilt
					$chain = $this->fileCache->chain($file->fileId, $root->rootId);
					if ($chain !== null && $withTags) {
						$this->want($tags, $file->fileId, fn () => $this->desiredTag($root, $chain, $ruleSet));
					}
				} else {
					$chain = $this->fileCache->chain($file->parentId, $root->rootId);
					if ($chain === null) {
						continue; // liegt nicht (mehr) unter der Bereichswurzel
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
	 * Ergänzt einen Batch um „zuerst gesehen“ und die letzte echte Löschung je Datei.
	 * Jede Datei ohne Eintrag gilt ab jetzt als gesehen – auch mit Upload-Zeit, denn Kopien
	 * erben die des Originals (ReferenceDate). Ohne Upload-Zeit ist sie damit nicht fällig.
	 * Zurückgeholte Dateien (letzte Löschung durch die App, danach nicht mehr gesehen) gelten
	 * ebenfalls ab jetzt als gesehen – ab der Wiederherstellung zählt die Frist neu (ReferenceDate).
	 * Vermerkt wird nur bei $record; Vorschau und Dry-Run rechnen mit „jetzt“.
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
	 * Unmittelbar vor dem Löschen: Ort, Elternkette, Regeln und Bezugsdatum frisch aus der DB.
	 * Der Scan kann Stunden alt sein (occ ohne Zeitbudget), die Elternketten stammen aus dem
	 * Zwischenspeicher – verschobene Dateien oder geänderte Regeln dürfen nicht nach altem
	 * Stand gelöscht werden.
	 *
	 * @return array{0: ?Decision, 1: ?string} frische Entscheidung oder Grund, warum nicht gelöscht wird
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
		if ($row->mtime !== $d->file->mtime) {
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
	 * Fehler einer einzelnen Datei brechen den Lauf nicht ab: protokollieren, weiter am Cursor.
	 *
	 * @return array{0: ?string, 1: ?string} Status (null = nicht fällig) und Meldung
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
					// Protokoll selbst nicht erreichbar – steht schon im Nextcloud-Log
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
			// Nach einer endgültigen Löschung: nichts mehr in diesem Bereich, bis ein Admin die Sperre aufhebt
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
			// Papierkorb hat in diesem Lauf eine Ausnahme geworfen – Nextcloud könnte danach am
			// Papierkorb vorbei löschen. Rest des Laufs: nichts mehr anfassen (Warnung steht im Nextcloud-Log)
			$stats->skipped++;
			$stats->blocked++;
			return [LogEntry::STATUS_SKIPPED_BLOCKED, $this->language->l10n()->t('Deletion halted for this run: %s', [$halted])];
		}

		// Notbremse: Simulation mitten im Lauf eingeschaltet (Oberfläche oder occ config:app:set).
		// Frisch aus der Datenbank – IAppConfig hält den Wert vom Prozessstart
		if ($this->settings->isSimulationFresh()) {
			if (!$this->simulationSwitched) {
				$this->simulationSwitched = true;
				$this->logger->warning('folder_retention: simulation mode switched on during the run – the rest of the run is only simulated. ' . $stats->summary($this->language->english()));
			}
			return $this->actDue($root, $d, true, false, $now, $stats);
		}

		// Sperre unmittelbar vor dem Löschen sicherstellen – nie zwei Läufe gleichzeitig
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
		// Erst den Status dieser Datei protokollieren, dann sperren und berichtigen: Ein Fehler beim
		// Sperren darf „deleted“/„deleted_final“ nicht durch „Interner Fehler“ ersetzen
		try {
			$this->log($root, $fresh, LogEntry::MODE_REAL, $status, $message, $this->time->getTime());
		} catch (Throwable $e) {
			// Die Löschung ist geschehen – nicht als „Fehler bei der Datei“ melden, sondern ehrlich hier
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
	 * Bereich sperren: sofort für diesen Lauf, dazu dauerhaft in der Datenbank. Scheitert das
	 * Speichern, steht es im Nextcloud-Log; dieser Lauf löscht dort trotzdem nichts mehr.
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
	 * Dateien, die in diesem Lauf als gelöscht verbucht wurden, deren Papierkorb-Eintrag Nextcloud
	 * aber inzwischen überschrieben hat (gleicher Name in derselben Sekunde): Protokoll berichtigen,
	 * Bereich sperren. Der Deleter hat das Löschen für den Rest des Laufs schon angehalten.
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
		$entry = new LogEntry();
		$entry->setFileId($d->file->fileId);
		$entry->setStorageId($d->file->storageId);
		$entry->setPath(mb_substr($root->displayPath($d->file->path), 0, 4000));
		$entry->setRuleId($rule->id);
		$entry->setRuleFolderId($rule->folderId);
		$entry->setRuleLabel($rule->logLabel($this->language->l10n()));
		$entry->setReferenceDate($d->reference->timestamp);
		$entry->setReferenceSource($d->reference->source);
		$entry->setDeletedAt($at);
		$entry->setMode($mode);
		$entry->setStatus($status);
		$entry->setMessage($message);
		$this->logMapper->insert($entry);
	}
}
