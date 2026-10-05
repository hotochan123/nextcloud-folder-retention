<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use DateTimeZone;
use OCA\FolderRetention\Db\LogEntry;
use OCA\FolderRetention\Db\LogMapper;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Model\RetentionRule;
use OCA\FolderRetention\Model\RuleSet;
use OCA\FolderRetention\Model\Scope;
use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\Deleter;
use OCA\FolderRetention\Service\Evaluator;
use OCA\FolderRetention\Service\FileCacheReader;
use OCA\FolderRetention\Service\FirstSeen;
use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\RootProvider;
use OCA\FolderRetention\Service\RuleResolver;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCA\FolderRetention\Service\TagService;
use OCA\FolderRetention\Tests\Unit\FakeL10N;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Job control: time budget, cursor, resuming, daily cycle, simulation vs. real,
 * recheck before deleting, per-file errors, locks.
 *
 * Two team folders A (storage 1, root 100) and B (storage 2, root 200) with three
 * files each, all far past the retention period (upload time 1, default rule 1 day).
 */
class RetentionRunnerTest extends TestCase {
	private const NOW = 1_800_000_000;

	private Settings&MockObject $settings;
	private Deleter&MockObject $deleter;
	private LogMapper&MockObject $logMapper;
	private TagService&MockObject $tags;
	private bool $tagsEnabled = false;
	private bool $tagApplyFails = false;
	/** @var array<int, ?int> all requests passed to TagService::apply */
	private array $appliedTags = [];
	private ?array $cursor = null;
	private int $lastCycle = 0;
	/** @var (callable(): void)|null app config state after Settings::refresh (another process has written) */
	private $onRefresh = null;
	private int $refreshes = 0;
	private int $budget = 0;
	private bool $simulation = true;
	/** Simulation switch in the database (Settings::isSimulationFresh); null = same as $simulation */
	private ?bool $simulationDb = null;
	/** Settings::blockRoot throws (DB timeout, deadlock) */
	private bool $blockFails = false;
	/** after this file is deleted, an admin turns on simulation mode (only in the database) */
	private ?int $simulationOnAfter = null;
	/** @var list<int> */
	private array $deletedIds = [];
	/** @var list<int> */
	private array $loggedIds = [];
	/** @var list<LogEntry> */
	private array $logged = [];
	/** @var array<int, list<FileRow>> storage → files, as returned by fetchFiles */
	private array $files = [];
	/** @var array<int, FileRow> differing state at recheck (fileid → row) */
	private array $freshRows = [];
	/** Rules from the second snapshot() call on (= recheck), null = unchanged */
	private ?RuleSet $freshRules = null;
	/** Retention period of the default rule (null = 1 day) */
	private ?Period $period = null;
	/** folder rules of the initial snapshot (folder ID → rule) */
	private array $folderRules = [];
	/** @var array<int, string> fileid → status the Deleter should return */
	private array $deleteResult = [];
	/** @var list<int> file IDs for which the Deleter throws an exception */
	private array $deleteThrows = [];
	/** @var array<int, int> fileid → last deletion according to the log */
	private array $lastDeleted = [];
	/** @var list<int> file IDs recorded via FirstSeen::record */
	private array $recordedSeen = [];
	/** @var list<int> file IDs recorded via FirstSeen::recordRestored */
	private array $recordedRestored = [];
	/** @var array<string, array{label: string, reason: string, at: int}> */
	private array $blocked = [];
	private ?array $foreignLease = null;
	private int $leaseReleased = 0;
	/** from which renewal on the lock belongs to someone else (null = never) */
	private ?int $leaseLostAt = null;
	private int $leaseRenewals = 0;
	/** Boundary existing/new (Settings::seenMaxFileId): all default files are existing */
	private ?int $seenMark = 1000;
	/** highest file ID in the file cache (for the boundary, if it is missing) */
	private int $maxFileId = 1000;
	/** @var list<int> values passed to initSeenMaxFileId */
	private array $markInit = [];
	/** @var array<int, list<array{0: RetentionRoot, 1: FileRow, 2: string}>> fileid → losses reported after deleting it */
	private array $lostAfter = [];
	/** @var list<array{0: RetentionRoot, 1: FileRow, 2: string}> */
	private array $pendingLost = [];
	/** @var array<int, string> entries corrected via markDeletedFinal */
	private array $markedFinal = [];
	/** File ID after whose deletion attempt the Deleter reports "halted" */
	private ?int $haltAfter = null;
	private ?string $halted = null;
	private FirstSeen&MockObject $firstSeen;
	/** Settings::deletionLimit, 0 = none */
	private int $deletionLimit = 0;
	/** Settings::cycleDeleted (persisted between job chunks) */
	private int $cycleDeleted = 0;
	/** @var array{at: int, limit: int}|null Settings::deletionHalt */
	private ?array $halt = null;
	/** @var list<int> log entry IDs (index + 1 in $logged) moved via LogMapper::touch */
	private array $touched = [];
	/** @var list<list<int>> file IDs per LogMapper::supersede() call */
	private array $supersededCalls = [];

	private function runner(bool $cli = true): RetentionRunner {
		$rootA = new RetentionRoot(RetentionRoot::KIND_TEAM, 1, 100, '__groupfolders/1', 'A', ['alice']);
		$rootB = new RetentionRoot(RetentionRoot::KIND_TEAM, 2, 200, '__groupfolders/2', 'B', ['bob']);
		$this->files = $this->files ?: [
			1 => [new FileRow(11, 1, 100, '__groupfolders/1/a', 1, null, 1), new FileRow(12, 1, 100, '__groupfolders/1/b', 1, null, 1), new FileRow(13, 1, 100, '__groupfolders/1/c', 1, null, 1),
				new FileRow(14, 1, 100, '__groupfolders/1/Unterordner', 1, null, 1, 0, true)],
			2 => [new FileRow(21, 2, 200, '__groupfolders/2/a', 1, null, 1), new FileRow(22, 2, 200, '__groupfolders/2/b', 1, null, 1), new FileRow(23, 2, 200, '__groupfolders/2/c', 1, null, 1)],
		];
		$files = &$this->files;

		$roots = $this->createMock(RootProvider::class);
		$roots->method('getRoots')->willReturn([$rootA, $rootB]);

		$fileCache = $this->createMock(FileCacheReader::class);
		$fileCache->method('fetchFiles')->willReturnCallback(
			fn (int $storage, string $prefix, int $after, int $limit, bool $folders = false) => array_values(array_slice(
				array_filter($files[$storage], fn (FileRow $f) => $f->fileId > $after && ($folders || !$f->isFolder)), 0, $limit)));
		$fileCache->method('maxFileId')->willReturnCallback(fn () => $this->maxFileId);
		$fileCache->method('chain')->willReturnCallback(fn (int $folder, int $stop) => [$folder]);
		$fileCache->method('freshChain')->willReturnCallback(fn (int $folder, int $stop) => $folder === $stop ? [$folder] : null);
		$fileCache->method('getFileRow')->willReturnCallback(function (int $id) {
			if (array_key_exists($id, $this->freshRows)) {
				return $this->freshRows[$id];
			}
			foreach ($this->files as $list) {
				foreach ($list as $f) {
					if ($f->fileId === $id) {
						return $f;
					}
				}
			}
			return null;
		});

		$rules = $this->createMock(RuleService::class);
		$snapshots = 0;
		$rules->method('snapshot')->willReturnCallback(function () use (&$snapshots) {
			$initial = new RuleSet(new RetentionRule(1, null, $this->period ?? Period::of(1, PeriodUnit::Day), null), $this->folderRules);
			return $snapshots++ > 0 && $this->freshRules !== null ? $this->freshRules : $initial;
		});

		$this->settings = $this->createMock(Settings::class);
		$this->settings->method('getCursor')->willReturnCallback(fn () => $this->cursor);
		$this->settings->method('setCursor')->willReturnCallback(function (?string $root, int $after = 0) {
			$this->cursor = $root === null ? null : ['root' => $root, 'after' => $after];
		});
		$this->settings->method('lastCycleCompleted')->willReturnCallback(fn () => $this->lastCycle);
		$this->settings->method('refresh')->willReturnCallback(function () {
			$this->refreshes++;
			if ($this->onRefresh !== null) {
				($this->onRefresh)();
			}
		});
		$this->settings->method('setLastCycleCompleted')->willReturnCallback(function (int $ts) {
			$this->lastCycle = $ts;
		});
		$this->settings->method('timeBudget')->willReturnCallback(fn () => $this->budget);
		$this->settings->method('batchSize')->willReturn(2);
		$this->settings->method('isSimulation')->willReturnCallback(fn () => $this->simulation);
		$this->settings->method('isSimulationFresh')->willReturnCallback(fn () => $this->simulationDb ?? $this->simulation);
		$this->settings->method('includePersonal')->willReturn(false);
		$this->settings->method('timezone')->willReturn(new DateTimeZone('Europe/Berlin'));
		$this->settings->method('tagsEnabled')->willReturnCallback(fn () => $this->tagsEnabled);
		$this->settings->method('acquireRunLease')->willReturnCallback(fn () => $this->foreignLease);
		$this->settings->method('releaseRunLease')->willReturnCallback(function () {
			$this->leaseReleased++;
		});
		$this->settings->method('renewRunLease')->willReturnCallback(fn () => $this->leaseLostAt === null || ++$this->leaseRenewals < $this->leaseLostAt);
		$this->settings->method('runLease')->willReturnCallback(fn () => $this->leaseLostAt === null ? null : ['holder' => 'Hintergrundjob (PID 2, y)', 'token' => 'fremd', 'until' => self::NOW + 900]);
		$this->settings->method('seenMaxFileId')->willReturnCallback(fn () => $this->seenMark);
		$this->settings->method('initSeenMaxFileId')->willReturnCallback(function (int $id) {
			$this->markInit[] = $id;
			$this->seenMark ??= max(1, $id);
		});
		$this->settings->method('deletionLimit')->willReturnCallback(fn () => $this->deletionLimit);
		$this->settings->method('cycleDeleted')->willReturnCallback(fn () => $this->cycleDeleted);
		$this->settings->method('setCycleDeleted')->willReturnCallback(function (int $n) {
			$this->cycleDeleted = $n;
		});
		$this->settings->method('deletionHalt')->willReturnCallback(fn () => $this->halt);
		$this->settings->method('haltDeletion')->willReturnCallback(function (int $limit, int $at) {
			$this->halt = ['at' => $at, 'limit' => $limit];
		});
		$this->settings->method('isRootBlocked')->willReturnCallback(fn (string $key) => isset($this->blocked[$key]));
		$this->settings->method('blockRoot')->willReturnCallback(function (string $key, string $label, string $reason, int $at) {
			if ($this->blockFails) {
				throw new \RuntimeException('Deadlock auf folder_retention_block');
			}
			$this->blocked[$key] = ['label' => $label, 'reason' => $reason, 'at' => $at];
		});

		$this->tags = $this->createMock(TagService::class);
		$this->tags->method('tagIdFor')->willReturn(7);
		$this->tags->method('apply')->willReturnCallback(function (array $desired) {
			if ($this->tagApplyFails) {
				throw new \RuntimeException('DB weg');
			}
			$this->appliedTags += $desired;
		});

		$this->deleter = $this->createMock(Deleter::class);
		$this->deleter->method('haltReason')->willReturnCallback(fn () => $this->halted);
		$this->deleter->method('delete')->willReturnCallback(function (RetentionRoot $root, FileRow $f) {
			if ($f->fileId === $this->haltAfter) {
				$this->halted = 'Papierkorb kaputt';
				return [LogEntry::STATUS_ERROR, 'Papierkorb kaputt – weitere Löschungen in diesem Lauf angehalten'];
			}
			if (in_array($f->fileId, $this->deleteThrows, true)) {
				throw new \RuntimeException('Speicher kaputt');
			}
			$status = $this->deleteResult[$f->fileId] ?? LogEntry::STATUS_DELETED;
			if ($status === LogEntry::STATUS_DELETED) {
				$this->deletedIds[] = $f->fileId;
			}
			if ($f->fileId === $this->simulationOnAfter) {
				$this->simulationDb = true;
			}
			if (isset($this->lostAfter[$f->fileId])) {
				array_push($this->pendingLost, ...$this->lostAfter[$f->fileId]);
				$this->halted = 'Papierkorb-Eintrag überschrieben';
			}
			return [$status, $status === LogEntry::STATUS_DELETED ? null : 'Meldung'];
		});

		$this->deleter->method('takeLost')->willReturnCallback(function () {
			$lost = $this->pendingLost;
			$this->pendingLost = [];
			return $lost;
		});

		$this->logMapper = $this->createMock(LogMapper::class);
		$this->logMapper->method('markDeletedFinal')->willReturnCallback(function (int $id, string $msg) {
			$this->markedFinal[$id] = $msg;
			return true;
		});
		// like the SQL query: same file, rule, retention period (rule_label) and same reference date incl. source
		$this->logMapper->method('hasSimulated')->willReturnCallback(fn (int $id, ?int $ruleId, string $label, int $ref, string $source) => array_filter(
			$this->logged,
			fn (LogEntry $e) => $e->getFileId() === $id && $e->getMode() === LogEntry::MODE_SIMULATION && $e->getStatus() === LogEntry::STATUS_WOULD_DELETE
				&& $e->getRuleId() === $ruleId && $e->getRuleLabel() === $label && $e->getReferenceDate() === $ref && $e->getReferenceSource() === $source,
		) !== []);
		$this->logMapper->method('insert')->willReturnCallback(function (LogEntry $e) {
			$this->loggedIds[] = $e->getFileId();
			$this->logged[] = $e;
			return $e;
		});
		// like the SQL query: newest entry of the file, if it says exactly the same; ID = index + 1
		$this->logMapper->method('findRepeat')->willReturnCallback(function (int $id, string $mode, string $status, ?string $label, ?string $message) {
			$last = null;
			foreach ($this->logged as $i => $e) {
				if ($e->getFileId() === $id) {
					$last = $i;
				}
			}
			if ($last === null) {
				return null;
			}
			$e = $this->logged[$last];
			return $e->getMode() === $mode && $e->getStatus() === $status && $e->getRuleLabel() === $label && $e->getMessage() === $message ? $last + 1 : null;
		});
		$this->logMapper->method('touch')->willReturnCallback(function (int $id, int $at) {
			$this->logged[$id - 1]->setDeletedAt($at);
			$this->touched[] = $id;
		});
		$this->logMapper->method('supersede')->willReturnCallback(function (array $ids) {
			$this->supersededCalls[] = array_values($ids);
			return 0;
		});
		$this->logMapper->method('lastDeleted')->willReturnCallback(
			fn (array $ids) => array_intersect_key($this->lastDeleted, array_flip($ids)));

		$this->firstSeen = $this->createMock(FirstSeen::class);
		$this->firstSeen->method('record')->willReturnCallback(function (array $ids) {
			array_push($this->recordedSeen, ...$ids);
		});
		$this->firstSeen->method('recordRestored')->willReturnCallback(function (array $ids) {
			array_push($this->recordedRestored, ...$ids);
		});

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('token');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		// stored texts (log) in German, as on existing installs
		$language = $this->createMock(ContentLanguage::class);
		$language->method('l10n')->willReturn(FakeL10N::de());
		$language->method('english')->willReturn(FakeL10N::en());

		$args = [$roots, $fileCache, new Evaluator(new RuleResolver()), $rules,
			$this->settings, $this->deleter, $this->logMapper, $time, new NullLogger(), $this->tags, new RuleResolver(), $this->firstSeen, $random, FakeL10N::de(), $language];
		if (!$cli) {
			// cron.php via web (AJAX/webcron)
			return new class(...$args) extends RetentionRunner {
				protected function isCli(): bool {
					return false;
				}
			};
		}
		return new RetentionRunner(...$args);
	}

	public function testBudgetZeroProcessesOneFilePerRunAndResumesAtCursor(): void {
		$this->simulation = false;
		$this->budget = 0; // budget exhausted immediately → exactly one file per run
		$runner = $this->runner();

		$runs = 0;
		do {
			$stats = $runner->runScheduled();
			$runs++;
			$this->assertLessThan(20, $runs, 'Endlosschleife');
		} while (!$stats->completed);

		$this->assertSame([11, 12, 13, 21, 22, 23], $this->deletedIds, 'jede Datei genau einmal, in Reihenfolge');
		$this->assertNull($this->cursor, 'Cursor nach Zyklusende gelöscht');
		$this->assertSame(self::NOW, $this->lastCycle);
	}

	public function testCursorIsStoredPerRoot(): void {
		$this->budget = 0;
		$runner = $this->runner();
		$runner->runScheduled();
		$this->assertSame(['root' => 'team:0000000001:000000000100', 'after' => 11], $this->cursor);
		$runner->runScheduled();
		$runner->runScheduled();
		$runner->runScheduled();
		$this->assertSame('team:0000000002:000000000200', $this->cursor['root']);
		$this->assertSame(21, $this->cursor['after']);
	}

	public function testLargeBudgetCompletesInOneRun(): void {
		$this->budget = 3600;
		$stats = $this->runner()->runScheduled();
		$this->assertTrue($stats->completed);
		$this->assertSame(6, $stats->due);
		$this->assertNull($this->cursor);
	}

	public function testNoNewCycleWithin23Hours(): void {
		$this->budget = 3600;
		$this->lastCycle = self::NOW - 3600;
		$stats = $this->runner()->runScheduled();
		$this->assertTrue($stats->notDue);
		$this->assertSame(0, $stats->evaluated);
	}

	public function testCursorIsReReadAfterAcquiringTheLease(): void {
		// Process cache: cron.php still knows the cursor from before it started; while it was working
		// through other jobs, another run finished the cycle
		$this->budget = 3600;
		$this->cursor = ['root' => 'team:0000000001:000000000100', 'after' => 11];
		$this->onRefresh = function () {
			$this->cursor = null;
			$this->lastCycle = self::NOW - 60;
		};
		$stats = $this->runner()->runScheduled();
		$this->assertSame(1, $this->refreshes);
		$this->assertTrue($stats->notDue, 'frischer Stand: Zyklus gerade beendet');
		$this->assertSame(0, $stats->evaluated);
		$this->assertSame([], $this->deletedIds);
		$this->assertSame(1, $this->leaseReleased, 'Sperre wieder freigegeben');
	}

	public function testNewCycleAfter23Hours(): void {
		$this->budget = 3600;
		$this->lastCycle = self::NOW - 23 * 3600;
		$stats = $this->runner()->runScheduled();
		$this->assertFalse($stats->notDue);
		$this->assertSame(6, $stats->evaluated);
	}

	public function testSimulationNeverDeletesAndLogsOnce(): void {
		$this->simulation = true;
		$this->budget = 3600;
		$runner = $this->runner();
		$this->deleter->expects($this->never())->method('delete');

		$runner->runScheduled();
		$this->lastCycle = 0; // force the next cycle
		$runner->runScheduled();

		$this->assertSame([11, 12, 13, 21, 22, 23], $this->loggedIds, 'zweiter Zyklus loggt nicht erneut');
	}

	public function testSimulationLogsAgainWhenEvaluationChanges(): void {
		$this->simulation = true;
		$runner = $this->runner();
		$runner->runFull(false, null);
		$this->assertSame([11, 12, 13, 21, 22, 23], $this->loggedIds);

		// Legacy entry as from 0.7.x: different reference date/source → new, honest entry
		$this->logged[0]->setReferenceSource('mtime');
		$this->logged[1]->setReferenceDate(0);
		$runner->runFull(false, null);
		$this->assertSame([11, 12, 13, 21, 22, 23, 11, 12], $this->loggedIds);

		// Retention period changed on the same rule: rule_label changes → all new
		$this->period = Period::of(2, PeriodUnit::Day);
		$runner->runFull(false, null);
		$this->assertSame([11, 12, 13, 21, 22, 23, 11, 12, 11, 12, 13, 21, 22, 23], $this->loggedIds);
		$this->assertSame('Standard: 2 Tage', end($this->logged)->getRuleLabel());

		// unchanged: no repetition
		$runner->runFull(false, null);
		$this->assertCount(14, $this->loggedIds);
	}

	public function testRealRunSupersedesHitsOfFilesNotDueOrDeleted(): void {
		$this->simulation = false;
		$this->period = Period::never();
		$this->runner()->runFull(false, null);
		$this->assertSame([11, 12, 13, 21, 22, 23], array_merge(...$this->supersededCalls), 'not due');
		$this->assertCount(4, $this->supersededCalls, 'one query per batch (batch size 2), not per file');

		$this->supersededCalls = [];
		$this->period = null;
		$this->deleteResult = [12 => LogEntry::STATUS_SKIPPED_LOCKED, 13 => LogEntry::STATUS_ERROR];
		$this->runner()->runFull(false, null);
		$this->assertSame([11, 21, 22, 23], array_merge(...$this->supersededCalls), 'deleted: superseded; locked or failed: still due');
	}

	public function testSimulationSupersedesThePreviousEvaluationOnlyWhenLoggingANewOne(): void {
		$this->simulation = true;
		$runner = $this->runner();
		$runner->runFull(false, null);
		$this->assertSame([[11], [12], [13], [21], [22], [23]], $this->supersededCalls);

		$this->supersededCalls = [];
		$runner->runFull(false, null);
		$this->assertSame([], $this->supersededCalls, 'same evaluation: entry stays current');
	}

	public function testDryRunWritesNothing(): void {
		$this->simulation = false;
		$runner = $this->runner();
		$this->deleter->expects($this->never())->method('delete');
		$this->logMapper->expects($this->never())->method('insert');
		$this->logMapper->expects($this->never())->method('supersede');

		$stats = $runner->runFull(true, null);
		$this->assertSame(6, $stats->due);
	}

	public function testRunFullRespectsGlobalSimulation(): void {
		$this->simulation = true;
		$runner = $this->runner();
		$this->deleter->expects($this->never())->method('delete');
		$stats = $runner->runFull(false, null);
		$this->assertSame(6, $stats->simulated);
	}

	public function testRuleFilter(): void {
		$this->simulation = false;
		$stats = $this->runner()->runFull(false, 999);
		$this->assertSame(0, $stats->due);
		$this->assertSame([], $this->deletedIds);
	}

	public function testTagsDisabledLeavesTagsAlone(): void {
		$runner = $this->runner();
		$this->tags->expects($this->never())->method('apply');
		$this->tags->expects($this->never())->method('sweepOrphans');
		$runner->runFull(false, null);
		$this->budget = 3600;
		$runner->runScheduled();
	}

	public function testTagsCoverFilesFoldersAndRoots(): void {
		$this->tagsEnabled = true;
		$runner = $this->runner();
		$this->tags->expects($this->once())->method('sweepOrphans')->willReturn(0);

		$stats = $runner->runFull(false, null);

		$this->assertSame(6, $stats->evaluated, 'Ordner werden getaggt, aber nicht bewertet');
		$ids = array_keys($this->appliedTags);
		sort($ids);
		$this->assertSame([11, 12, 13, 14, 21, 22, 23, 100, 200], $ids);
		$this->assertSame([7], array_values(array_unique($this->appliedTags)));
	}

	public function testDryRunAndRuleFilterDoNotTag(): void {
		$this->tagsEnabled = true;
		$runner = $this->runner();
		$this->tags->expects($this->never())->method('apply');
		$runner->runFull(true, null);
		$runner->runFull(false, 1);
	}

	public function testTagFailureDoesNotStopDeletion(): void {
		$this->tagsEnabled = true;
		$this->simulation = false;
		$runner = $this->runner();
		$this->tagApplyFails = true;

		$stats = $runner->runFull(false, null);
		$this->assertSame([11, 12, 13, 21, 22, 23], $this->deletedIds);
		$this->assertSame(6, $stats->deleted);
	}

	// --- Recheck immediately before deleting (F6) ------------------------------------

	public function testMovedSinceScanIsSkippedNotDeleted(): void {
		$this->simulation = false;
		$runner = $this->runner();
		// Moved to another folder between scan and delete – mtime stays the same
		$this->freshRows[12] = new FileRow(12, 1, 150, '__groupfolders/1/Behalten/b', 1, null, 1);

		$stats = $runner->runFull(false, null);

		$this->assertSame([11, 13, 21, 22, 23], $this->deletedIds);
		$this->assertSame(1, $stats->skipped);
		$entry = $this->logEntryFor(12);
		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $entry->getStatus());
		$this->assertStringContainsString('verschoben', (string)$entry->getMessage());
	}

	public function testParentChainIsResolvedFreshNotFromScanCache(): void {
		$this->simulation = false;
		$runner = $this->runner();
		// Same path in the record, but the parent folder no longer sits under the root
		$this->freshRows[21] = new FileRow(21, 2, 999, '__groupfolders/2/a', 1, null, 1);

		$runner->runFull(false, null);

		$this->assertNotContains(21, $this->deletedIds);
		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $this->logEntryFor(21)->getStatus());
	}

	public function testDeletedSinceScanIsSkipped(): void {
		$this->simulation = false;
		$runner = $this->runner();
		$this->freshRows[13] = null;
		$runner->runFull(false, null);
		$this->assertNotContains(13, $this->deletedIds);
		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $this->logEntryFor(13)->getStatus());
	}

	public function testRuleChangedSinceScanIsSkipped(): void {
		$this->simulation = false;
		$runner = $this->runner();
		// Admin has since set the default retention period to "Never"
		$this->freshRules = new RuleSet(new RetentionRule(1, null, Period::never(), null), []);

		$stats = $runner->runFull(false, null);

		$this->assertSame([], $this->deletedIds);
		$this->assertSame(6, $stats->skipped);
		$this->assertStringContainsString('nicht mehr fällig', (string)$this->logEntryFor(11)->getMessage());
	}

	public function testOtherRuleAtRecheckIsSkipped(): void {
		$this->simulation = false;
		$runner = $this->runner();
		// New folder rule at the root (same retention period, different rule)
		$this->freshRules = new RuleSet(new RetentionRule(1, null, Period::of(1, PeriodUnit::Day), null),
			[100 => new RetentionRule(5, 100, Period::of(1, PeriodUnit::Day), \OCA\FolderRetention\Model\Scope::Inherit)]);

		$runner->runFull(false, null);

		$this->assertSame([21, 22, 23], $this->deletedIds);
		$this->assertStringContainsString('Regel', (string)$this->logEntryFor(11)->getMessage());
	}

	// --- Restored files and "first seen" (F3, F4) -----------------------------------

	public function testRestoredFileIsNotDeletedAgain(): void {
		$this->simulation = false;
		$this->lastDeleted = [11 => self::NOW - 3600];

		$this->runner()->runFull(false, null);

		$this->assertNotContains(11, $this->deletedIds, 'gestern gelöscht, heute wiederhergestellt → nicht wieder weg');
		$this->assertContains(12, $this->deletedIds);
	}

	public function testFileRestoredLongAfterDeletionCountsFromRestore(): void {
		// Rule 1 day; deleted 10 days ago, only restored now (entry from before the deletion)
		$this->simulation = false;
		$this->lastDeleted = [11 => self::NOW - 86400 * 10];
		$this->files = [
			1 => [new FileRow(11, 1, 100, '__groupfolders/1/a', 1, 1, 1, firstSeen: self::NOW - 86400 * 300), new FileRow(12, 1, 100, '__groupfolders/1/b', 1, null, 1, firstSeen: 5)],
			2 => [],
		];

		$this->runner()->runFull(false, null);

		$this->assertSame([11], $this->recordedRestored, 'Wiederherstellung als „zuerst gesehen“ vermerkt');
		$this->assertSame([], $this->recordedSeen);
		$this->assertSame([12], $this->deletedIds, 'zurückgeholt → nicht sofort wieder weg');

		// One day after the restore (first_seen now after it) it is due again
		$this->recordedRestored = [];
		$this->deletedIds = [];
		$this->files[1] = [new FileRow(11, 1, 100, '__groupfolders/1/a', 1, 1, 1, firstSeen: self::NOW - 86400 * 2)];
		$this->runner()->runFull(false, null);
		$this->assertSame([], $this->recordedRestored, 'schon nach der Löschung gesehen – nicht neu vermerken');
		$this->assertSame([11], $this->deletedIds);
	}

	public function testDryRunDoesNotRecordRestoreButCountsFromNow(): void {
		$this->lastDeleted = [11 => self::NOW - 86400 * 10];
		$this->files = [1 => [new FileRow(11, 1, 100, '__groupfolders/1/a', 1, 1, 1, firstSeen: self::NOW - 86400 * 300)], 2 => []];
		$runner = $this->runner();
		$stats = $runner->runFull(true, null);
		$this->assertSame(0, $stats->due);
		$this->assertSame([], $this->recordedRestored);
	}

	public function testFilesWithoutUploadTimeAreRecordedAndNotDue(): void {
		$this->simulation = false;
		$this->files = [
			1 => [new FileRow(11, 1, 100, '__groupfolders/1/alt', 1, 1, 0), new FileRow(12, 1, 100, '__groupfolders/1/gesehen', 1, 1, null, firstSeen: 5)],
			2 => [],
		];
		$stats = $this->runner()->runFull(false, null);

		$this->assertSame([11], $this->recordedSeen, 'nur die Datei ohne Eintrag');
		$this->assertSame([12], $this->deletedIds, 'ohne Eintrag: ab jetzt gesehen, nicht fällig');
		$this->assertSame(1, $stats->due);
	}

	public function testDryRunAndPreviewDoNotRecordFirstSeen(): void {
		$this->files = [1 => [new FileRow(11, 1, 100, '__groupfolders/1/alt', 1, 1, 0)], 2 => []];
		$runner = $this->runner();
		$runner->runFull(true, null);
		$runner->preview(null, 7, 10, 10.0);
		$this->assertSame([], $this->recordedSeen);
	}

	public function testSimulationRecordsFirstSeen(): void {
		$this->simulation = true;
		$this->budget = 3600;
		$this->files = [1 => [new FileRow(11, 1, 100, '__groupfolders/1/alt', 1, 1, 0)], 2 => []];
		$this->runner()->runScheduled();
		$this->assertSame([11], $this->recordedSeen);
	}

	// --- Per-file errors (F10) ----------------------------------------------------------

	public function testExceptionForOneFileDoesNotStopTheRun(): void {
		$this->simulation = false;
		$this->budget = 3600;
		$this->deleteThrows = [12];

		$stats = $this->runner()->runScheduled();

		$this->assertTrue($stats->completed);
		$this->assertSame([11, 13, 21, 22, 23], $this->deletedIds);
		$this->assertSame(1, $stats->errors);
		$this->assertSame(LogEntry::STATUS_ERROR, $this->logEntryFor(12)->getStatus());
		$this->assertNull($this->cursor);
	}

	public function testExceptionKeepsCursorMovingWithBudget(): void {
		$this->simulation = false;
		$this->budget = 0;
		$this->deleteThrows = [11];
		$runner = $this->runner();
		$runner->runScheduled();
		$this->assertSame(['root' => 'team:0000000001:000000000100', 'after' => 11], $this->cursor, 'Cursor steht hinter der fehlerhaften Datei');
		$runner->runScheduled();
		$this->assertSame([12], $this->deletedIds);
	}

	public function testBrokenLogInsertIsCaughtPerFile(): void {
		$this->simulation = true;
		$runner = $this->runner();
		$calls = 0;
		$this->logMapper->method('hasSimulated')->willReturnCallback(function () use (&$calls) {
			if ($calls++ === 0) {
				throw new \RuntimeException('DB weg');
			}
			return false;
		});
		$stats = $runner->runFull(false, null);
		$this->assertSame(6, $stats->due);
		$this->assertSame(1, $stats->errors);
	}

	// --- Permanent deletion detected (F2) ----------------------------------------------

	public function testPermanentDeletionBlocksRootButNotOthers(): void {
		$this->simulation = false;
		$this->deleteResult[11] = LogEntry::STATUS_DELETED_FINAL;

		$stats = $this->runner()->runFull(false, null);

		$this->assertSame([21, 22, 23], $this->deletedIds, 'in A nach der ersten endgültigen Löschung nichts mehr');
		$this->assertArrayHasKey('0000000001:000000000100', $this->blocked);
		$this->assertSame(1, $stats->errors);
		$this->assertSame(2, $stats->blocked);
		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $this->logEntryFor(11)->getStatus());
		$this->assertNull($this->logEntryFor(12), 'gesperrte Dateien werden nicht einzeln protokolliert');
	}

	public function testBlockedRootStaysBlockedInNextRun(): void {
		$this->simulation = false;
		$this->blocked['0000000002:000000000200'] = ['label' => 'B', 'reason' => 'x', 'at' => 1];
		$reported = [];
		$stats = $this->runner()->runFull(false, null, function ($root, $d, string $status) use (&$reported) {
			$reported[$d->file->fileId] = $status;
		});
		$this->assertSame([11, 12, 13], $this->deletedIds);
		$this->assertSame(LogEntry::STATUS_SKIPPED_BLOCKED, $reported[21]);
		$this->assertSame(3, $stats->blocked);
	}

	public function testSimulationIgnoresBlock(): void {
		$this->simulation = true;
		$this->blocked['0000000001:000000000100'] = ['label' => 'A', 'reason' => 'x', 'at' => 1];
		$stats = $this->runner()->runFull(false, null);
		$this->assertSame(6, $stats->simulated);
	}

	// --- Lock against parallel runs (F9) -----------------------------------------------

	public function testRunFullRefusesWhileLocked(): void {
		$this->simulation = false;
		$this->foreignLease = ['holder' => 'Hintergrundjob (PID 1, x)', 'token' => 'fremd', 'until' => self::NOW + 600];
		$runner = $this->runner();
		$this->deleter->expects($this->never())->method('delete');

		$stats = $runner->runFull(false, null);

		$this->assertStringContainsString('Hintergrundjob', (string)$stats->lockedBy);
		$this->assertFalse($stats->completed);
		$this->assertSame(0, $this->leaseReleased, 'fremde Sperre nicht freigeben');
	}

	public function testJobSkipsWhileLockedAndKeepsCursor(): void {
		$this->budget = 3600;
		$this->foreignLease = ['holder' => 'occ', 'token' => 'fremd', 'until' => self::NOW + 600];
		$stats = $this->runner()->runScheduled();
		$this->assertNotNull($stats->lockedBy);
		$this->assertSame(0, $stats->evaluated);
		$this->assertSame(0, $this->lastCycle);
	}

	public function testDryRunNeedsNoLock(): void {
		$this->foreignLease = ['holder' => 'occ', 'token' => 'fremd', 'until' => self::NOW + 600];
		$stats = $this->runner()->runFull(true, null);
		$this->assertNull($stats->lockedBy);
		$this->assertSame(6, $stats->due);
	}

	public function testLeaseAndContextReleasedAfterRun(): void {
		$this->simulation = false;
		$runner = $this->runner();
		$this->deleter->expects($this->once())->method('releaseContext');
		$runner->runFull(false, null);
		$this->assertSame(1, $this->leaseReleased);
	}

	public function testLeaseLostMidRunStopsDeleting(): void {
		$this->simulation = false;
		// first deletion: lock renewed; second: meanwhile taken over by another run
		$this->leaseLostAt = 2;
		$runner = $this->runner();

		$stats = $runner->runFull(false, null);

		$this->assertSame([11], $this->deletedIds, 'nach Verlust der Sperre nichts mehr gelöscht');
		$this->assertFalse($stats->completed);
		$this->assertStringContainsString('verloren', (string)$stats->lockedBy);
		$this->assertSame(0, $this->leaseReleased, 'fremde Sperre nicht freigeben');
		$this->assertNull($this->logEntryFor(12), 'kein Fehler-Eintrag für die Datei, an der abgebrochen wurde');
	}

	public function testLeaseLostKeepsJobCursor(): void {
		$this->simulation = false;
		$this->budget = 3600;
		$this->cursor = ['root' => 'team:0000000001:000000000100', 'after' => 11];
		$this->leaseLostAt = 1;
		$stats = $this->runner()->runScheduled();
		$this->assertSame([], $this->deletedIds);
		$this->assertNotNull($stats->lockedBy);
		$this->assertSame(['root' => 'team:0000000001:000000000100', 'after' => 11], $this->cursor);
		$this->assertSame(0, $this->lastCycle);
	}

	// --- Exception in the trash bin: rest of the run halted (Deleter::haltReason) -----

	public function testHaltedDeleterStopsTheWholeRun(): void {
		$this->simulation = false;
		$this->haltAfter = 12;
		$reported = [];
		$stats = $this->runner()->runFull(false, null, function ($root, $d, string $status) use (&$reported) {
			$reported[$d->file->fileId] = $status;
		});

		$this->assertSame([11], $this->deletedIds, 'auch im anderen Team-Ordner nichts mehr');
		$this->assertSame(LogEntry::STATUS_ERROR, $this->logEntryFor(12)->getStatus());
		$this->assertSame(LogEntry::STATUS_SKIPPED_BLOCKED, $reported[21]);
		$this->assertSame(4, $stats->blocked);
		$this->assertNull($this->logEntryFor(13), 'angehaltene Dateien werden nicht einzeln protokolliert');
	}

	// --- "first seen" also alongside upload time (copies): boundary via the file ID -------

	public function testCopyWithInheritedUploadTimeCountsFromFirstSeen(): void {
		$this->simulation = false;
		$this->seenMark = 20; // highest file ID at the update
		$this->files = [
			1 => [
				// existing, only now seen in the first 0.8 cycle: upload time applies
				new FileRow(12, 1, 100, '__groupfolders/1/bestand', 1, 1, 1, firstSeen: self::NOW - 3600),
				// copy after the update: inherits upload_time 1 from the original, never seen yet
				new FileRow(21, 1, 100, '__groupfolders/1/kopie', 1, 1, 1),
				// created after the update, first seen 40 days ago – 1-day retention period has passed
				new FileRow(22, 1, 100, '__groupfolders/1/alt', 1, 1, 1, firstSeen: self::NOW - 86400 * 40),
				// created after the update, first seen an hour ago – retention period not yet passed
				new FileRow(23, 1, 100, '__groupfolders/1/neu', 1, 1, 1, firstSeen: self::NOW - 3600),
			],
			2 => [],
		];

		$this->runner()->runFull(false, null);

		$this->assertSame([21], $this->recordedSeen, 'jede Datei ohne Eintrag wird vermerkt, auch mit Upload-Zeit');
		$this->assertSame([12, 22], $this->deletedIds);
	}

	public function testCopyAppearingDuringFirstCycleIsProtected(): void {
		// The first cycle after the update spans several job executions; a copy created during
		// that time still counts from when it was first seen, not as existing
		$this->simulation = false;
		$this->budget = 0;
		$this->seenMark = 20;
		$this->files = [
			1 => [new FileRow(11, 1, 100, '__groupfolders/1/a', 1, null, 1), new FileRow(12, 1, 100, '__groupfolders/1/b', 1, null, 1)],
			// copy in the not-yet-scanned area (created after the first execution) – inherits the old upload time
			2 => [new FileRow(15, 2, 200, '__groupfolders/2/a', 1, null, 1), new FileRow(25, 2, 200, '__groupfolders/2/kopie', 1, 1, 1)],
		];
		$runner = $this->runner();
		$this->assertFalse($runner->runScheduled()->completed, 'erster Zyklus über mehrere Ausführungen');
		$runs = 0;
		do {
			$stats = $runner->runScheduled();
			$this->assertLessThan(20, ++$runs, 'Endlosschleife');
		} while (!$stats->completed);

		$this->assertSame([11, 12, 15], $this->deletedIds, 'Kopie bleibt');
		$this->assertContains(25, $this->recordedSeen);
	}

	public function testMissingMarkIsSetAtRunStart(): void {
		$this->simulation = false;
		$this->seenMark = null;
		$this->maxFileId = 500;
		$this->runner()->runFull(false, null);
		$this->assertSame([500], $this->markInit);
		$this->assertSame([11, 12, 13, 21, 22, 23], $this->deletedIds, 'Bestand bis zur Grenze: Upload-Zeit');
	}

	public function testWithoutMarkPreviewTreatsFilesCautiouslyAsNew(): void {
		$this->seenMark = null;
		$result = $this->runner()->preview(null, 0, 100, 10.0);
		$this->assertSame(0, $result['total'], 'ohne Grenze zählt „zuerst gesehen“ (jetzt) – nichts fällig');
		$this->assertSame([], $this->markInit, 'Vorschau setzt nichts');
	}

	public function testPreviewOfAllAreasIncludesFolderRules(): void {
		// area A (root folder 100) has its own rule, area B follows the default rule
		$this->folderRules = [100 => new RetentionRule(5, 100, Period::of(1, PeriodUnit::Day), Scope::Inherit)];
		$runner = $this->runner();

		$defaultOnly = $runner->preview(null, 7, 100, 10.0);
		$this->assertSame([21, 22, 23], array_column($defaultOnly['items'], 'fileId'), 'default rule: only area B');

		$all = $runner->preview(null, 7, 100, 10.0, all: true);
		$this->assertSame(6, $all['total']);
		$this->assertEqualsCanonicalizing([11, 12, 13, 21, 22, 23], array_column($all['items'], 'fileId'));
		$this->assertSame('0000000001:000000000100', $all['items'][array_search(11, array_column($all['items'], 'fileId'), true)]['root']);
	}

	public function testPreviewLimitKeepsTheEarliestDueNotTheFirstFound(): void {
		$this->files = [
			1 => [new FileRow(11, 1, 100, '__groupfolders/1/a', 1, null, 300), new FileRow(12, 1, 100, '__groupfolders/1/b', 1, null, 100),
				new FileRow(13, 1, 100, '__groupfolders/1/c', 1, null, 200)],
			2 => [new FileRow(21, 2, 200, '__groupfolders/2/a', 1, null, 50), new FileRow(22, 2, 200, '__groupfolders/2/b', 1, null, 400)],
		];
		$result = $this->runner()->preview(null, 7, 2, 10.0, all: true);
		$this->assertSame(5, $result['total']);
		$this->assertTrue($result['truncated']);
		$this->assertSame([21, 12], array_column($result['items'], 'fileId'));
	}

	public function testDryRunDoesNotSetMark(): void {
		$this->seenMark = null;
		$this->runner()->runFull(true, null);
		$this->assertSame([], $this->markInit);
	}

	// --- Web cron: only system cron/occ deletes --------------------------------------

	public function testWebCronDoesNothing(): void {
		$this->simulation = false;
		$this->budget = 3600;
		$this->cursor = ['root' => 'team:0000000001:000000000100', 'after' => 11];
		$stats = $this->runner(false)->runScheduled();

		$this->assertTrue($stats->notCli);
		$this->assertFalse($stats->completed);
		$this->assertSame([], $this->deletedIds);
		$this->assertSame([], $this->logged);
		$this->assertSame(['root' => 'team:0000000001:000000000100', 'after' => 11], $this->cursor, 'Cursor unverändert');
		$this->assertSame(0, $this->leaseReleased, 'keine Sperre geholt');
		$this->assertSame([], $this->blocked, 'keine Sperre gesetzt');
	}

	// --- Trash bin entry overwritten afterwards (same name, same second) ---

	public function testLostTrashEntryIsCorrectedInLogAndBlocksRoot(): void {
		$this->simulation = false;
		$rootA = new RetentionRoot(RetentionRoot::KIND_TEAM, 1, 100, '__groupfolders/1', 'A', ['alice']);
		$this->lostAfter[12] = [[$rootA, new FileRow(11, 1, 100, '__groupfolders/1/a', 1, null, 1), 'Papierkorb-Eintrag überschrieben']];

		$stats = $this->runner()->runFull(false, null);

		$this->assertSame([11 => 'Papierkorb-Eintrag überschrieben'], $this->markedFinal, 'früherer „deleted“-Eintrag wird berichtigt');
		$this->assertArrayHasKey($rootA->blockKey(), $this->blocked);
		$this->assertSame([11, 12], $this->deletedIds, 'danach nichts mehr gelöscht');
		$this->assertSame(1, $stats->deleted, 'nur noch eine Datei zählt als in den Papierkorb verschoben');
		$this->assertSame(1, $stats->errors);
	}

	public function testLostTrashEntryFoundAtRunEndIsCorrectedBeforeRelease(): void {
		$this->simulation = false;
		$rootB = new RetentionRoot(RetentionRoot::KIND_TEAM, 2, 200, '__groupfolders/2', 'B', ['bob']);
		$order = [];
		$runner = $this->runner();
		$this->deleter->expects($this->once())->method('verifyRecentTrash')->willReturnCallback(function () use (&$order, $rootB) {
			$order[] = 'verify';
			$this->pendingLost[] = [$rootB, new FileRow(23, 2, 200, '__groupfolders/2/c', 1, null, 1), 'Papierkorb-Eintrag kurz nach dem Verschieben verschwunden'];
		});
		$this->deleter->method('releaseContext')->willReturnCallback(function () use (&$order) {
			$order[] = 'release:' . count($this->markedFinal);
		});

		$stats = $runner->runFull(false, null);

		$this->assertSame(['verify', 'release:1'], $order, 'erst nachprüfen und berichtigen, dann Kontext abbauen');
		$this->assertSame([23 => 'Papierkorb-Eintrag kurz nach dem Verschieben verschwunden'], $this->markedFinal);
		$this->assertArrayHasKey($rootB->blockKey(), $this->blocked);
		$this->assertSame(1, $this->leaseReleased);
		$this->assertSame(5, $stats->deleted);
		$this->assertSame(1, $stats->errors);
	}

	public function testRunEndCheckFailingStillReleases(): void {
		$this->simulation = false;
		$runner = $this->runner();
		$this->deleter->method('verifyRecentTrash')->willThrowException(new \RuntimeException('DB weg'));
		$this->deleter->expects($this->once())->method('releaseContext');
		$runner->runFull(false, null);
		$this->assertSame(1, $this->leaseReleased);
	}

	// --- Error while blocking does not falsify the log ---

	public function testFailingBlockKeepsDeletedFinalInLogAndStopsArea(): void {
		$this->simulation = false;
		$this->blockFails = true;
		$this->deleteResult[11] = LogEntry::STATUS_DELETED_FINAL;
		$reported = [];

		$stats = $this->runner()->runFull(false, null, function ($root, $d, string $status) use (&$reported) {
			$reported[$d->file->fileId] = $status;
		});

		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $this->logEntryFor(11)?->getStatus(), 'endgültige Löschung steht im Log, nicht „Interner Fehler“');
		$this->assertSame(LogEntry::STATUS_DELETED_FINAL, $reported[11]);
		$this->assertSame([21, 22, 23], $this->deletedIds, 'in A nichts mehr gelöscht, obwohl die Sperre nicht gespeichert wurde');
		$this->assertSame(LogEntry::STATUS_SKIPPED_BLOCKED, $reported[12]);
		$this->assertSame(1, $stats->errors);
		$this->assertSame(2, $stats->blocked);
	}

	public function testFailingBlockAfterLostEntryKeepsDeletedStatusOfCurrentFile(): void {
		$this->simulation = false;
		$this->blockFails = true;
		$rootA = new RetentionRoot(RetentionRoot::KIND_TEAM, 1, 100, '__groupfolders/1', 'A', ['alice']);
		$this->lostAfter[12] = [[$rootA, new FileRow(11, 1, 100, '__groupfolders/1/a', 1, null, 1), 'Papierkorb-Eintrag überschrieben']];

		$stats = $this->runner()->runFull(false, null);

		$this->assertSame(LogEntry::STATUS_DELETED, $this->logEntryFor(12)?->getStatus(), 'Datei 12 liegt im Papierkorb – „deleted“ bleibt (sonst nach Wiederherstellung sofort wieder fällig)');
		$this->assertCount(1, array_filter($this->logged, fn (LogEntry $e) => $e->getFileId() === 12), 'kein zusätzlicher Fehler-Eintrag');
		$this->assertSame([11 => 'Papierkorb-Eintrag überschrieben'], $this->markedFinal, 'früherer Eintrag trotzdem berichtigt');
		$this->assertSame([11, 12], $this->deletedIds);
		$this->assertSame(1, $stats->errors);
	}

	// --- Simulation mode turned on mid-run (emergency brake) ---

	public function testSimulationSwitchedOnDuringRunFullStopsDeleting(): void {
		$this->simulation = false;
		$this->simulationOnAfter = 12; // Admin: occ config:app:set folder_retention simulation_mode --value=1
		$runner = $this->runner();
		$reported = [];

		$stats = $runner->runFull(false, null, function ($root, $d, string $status) use (&$reported) {
			$reported[$d->file->fileId] = $status;
		});

		$this->assertSame([11, 12], $this->deletedIds, 'nach dem Umschalten nichts mehr gelöscht');
		$this->assertSame(LogEntry::STATUS_WOULD_DELETE, $reported[13]);
		$this->assertSame(LogEntry::MODE_SIMULATION, $this->logEntryFor(13)?->getMode(), 'ehrlich als Simulation protokolliert');
		$this->assertSame(LogEntry::MODE_SIMULATION, $this->logEntryFor(23)?->getMode());
		$this->assertSame(2, $stats->deleted);
		$this->assertSame(4, $stats->simulated);
	}

	public function testSimulationSwitchedOnStopsTheJobToo(): void {
		$this->simulation = false;
		$this->budget = 3600;
		$this->simulationDb = true; // turned on after process start (cron.php), cache still says OFF

		$stats = $this->runner()->runScheduled();

		$this->assertSame([], $this->deletedIds);
		$this->assertSame(6, $stats->simulated);
		$this->assertSame(0, $stats->deleted);
	}

	public function testSimulationSwitchIsForgottenAfterTheRun(): void {
		$this->simulation = false;
		$this->simulationDb = true;
		$runner = $this->runner();
		$runner->runFull(false, null);
		$this->simulationDb = false; // turned off again
		$this->logged = [];
		$runner->runFull(false, null);
		$this->assertSame([11, 12, 13, 21, 22, 23], $this->deletedIds);
	}

	// --- Content changed with the same mtime (client keeps it on upload) ------------

	public function testSizeChangedSinceScanIsSkipped(): void {
		$this->simulation = false;
		$runner = $this->runner();
		$this->freshRows[12] = new FileRow(12, 1, 100, '__groupfolders/1/b', 1, null, 1, 99);

		$runner->runFull(false, null);

		$this->assertNotContains(12, $this->deletedIds);
		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $this->logEntryFor(12)->getStatus());
	}

	public function testEtagChangedSinceScanIsSkipped(): void {
		$this->simulation = false;
		$runner = $this->runner();
		$this->freshRows[12] = new FileRow(12, 1, 100, '__groupfolders/1/b', 1, null, 1, etag: 'neu');

		$runner->runFull(false, null);

		$this->assertNotContains(12, $this->deletedIds);
		$this->assertSame(LogEntry::STATUS_SKIPPED_CHANGED, $this->logEntryFor(12)->getStatus());
	}

	// --- Repeated reports ----------------------------------------------------------

	public function testRepeatedErrorMovesEntryInsteadOfAddingOne(): void {
		$this->simulation = false;
		$this->deleteResult[11] = LogEntry::STATUS_ERROR;

		$this->runner()->runFull(false, null);
		$this->runner()->runFull(false, null);

		$this->assertCount(1, array_filter($this->logged, fn (LogEntry $e) => $e->getFileId() === 11), 'one entry per error, not one per run');
		$this->assertSame([1], $this->touched);
	}

	public function testDifferentErrorGetsItsOwnEntry(): void {
		$this->simulation = false;
		$this->deleteResult[11] = LogEntry::STATUS_ERROR;
		$this->runner()->runFull(false, null);
		$this->deleteResult[11] = LogEntry::STATUS_SKIPPED_LOCKED;
		$this->runner()->runFull(false, null);

		$this->assertCount(2, array_filter($this->logged, fn (LogEntry $e) => $e->getFileId() === 11));
		$this->assertSame([], $this->touched);
	}

	// --- Deletion limit (optional emergency brake) -----------------------------------

	public function testDeletionLimitHaltsTheRunAndLaterRuns(): void {
		$this->simulation = false;
		$this->deletionLimit = 2;
		$reported = [];
		$stats = $this->runner()->runFull(false, null, function ($root, $d, string $status) use (&$reported) {
			$reported[$d->file->fileId] = $status;
		});

		$this->assertSame([11, 12], $this->deletedIds);
		$this->assertSame(['at' => self::NOW, 'limit' => 2], $this->halt);
		$this->assertSame(LogEntry::STATUS_SKIPPED_BLOCKED, $reported[13]);
		$this->assertSame(4, $stats->blocked);
		$this->assertNull($this->logEntryFor(13), 'held-back files are not logged one by one');

		$this->runner()->runFull(false, null);
		$this->assertSame([11, 12], $this->deletedIds, 'nothing more until an admin resumes');
	}

	public function testDeletionLimitCountsAcrossJobChunks(): void {
		$this->simulation = false;
		$this->budget = 0; // one file per job execution
		$this->deletionLimit = 3;
		$runner = $this->runner();

		$runs = 0;
		do {
			$stats = $runner->runScheduled();
			$this->assertLessThan(20, ++$runs);
		} while (!$stats->completed);

		$this->assertSame([11, 12, 13], $this->deletedIds);
		$this->assertNotNull($this->halt);
		$this->assertSame(0, $this->cycleDeleted, 'count reset at the end of the cycle');
	}

	public function testNewCycleStartsCountingAnew(): void {
		$this->simulation = false;
		$this->budget = 1000;
		$this->deletionLimit = 3;
		$this->cycleDeleted = 3; // left over from an aborted earlier cycle

		$this->runner()->runScheduled();

		$this->assertSame([11, 12, 13], $this->deletedIds);
	}

	public function testSimulationIgnoresDeletionLimit(): void {
		$this->deletionLimit = 1;
		$stats = $this->runner()->runFull(false, null);
		$this->assertSame(6, $stats->simulated);
		$this->assertNull($this->halt);
	}

	public function testWithoutLimitNothingIsHalted(): void {
		$this->simulation = false;
		$this->runner()->runFull(false, null);
		$this->assertCount(6, $this->deletedIds);
		$this->assertNull($this->halt);
	}

	private function logEntryFor(int $fileId): ?LogEntry {
		foreach ($this->logged as $e) {
			if ($e->getFileId() === $fileId) {
				return $e;
			}
		}
		return null;
	}
}
