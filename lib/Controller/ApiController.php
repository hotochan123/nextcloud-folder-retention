<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Controller;

use OCA\FolderRetention\AppInfo\Application;
use OCA\FolderRetention\BackgroundJob\TagSyncJob;
use OCA\FolderRetention\Db\LogMapper;
use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\LogSummary;
use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\RootProvider;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCA\FolderRetention\Service\TagService;
use OCA\FolderRetention\Service\TreeService;
use OCA\FolderRetention\Service\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\StreamTraversableResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Admin API. No #[NoAdminRequired] attributes → administrators only.
 * Paths relative to /apps/folder_retention.
 */
class ApiController extends Controller {
	private const PREVIEW_LIMIT = 500;
	private const PREVIEW_BUDGET = 20.0;
	/** longest time span for /api/log/folders */
	private const LOG_FOLDERS_SPAN = 31 * 86400;
	/** path value for the default rule of personal folders */
	private const PERSONAL = 'personal';
	/** Preview of every area (tab "Upcoming" next to the log) */
	private const ALL = 'all';
	private const UPCOMING_LIMIT = 2000;

	public function __construct(
		IRequest $request,
		private RuleService $rules,
		private TreeService $tree,
		private RootProvider $roots,
		private RetentionRunner $runner,
		private LogMapper $logMapper,
		private LogSummary $logSummary,
		private Settings $settings,
		private IUserSession $userSession,
		private TagService $tags,
		private IJobList $jobList,
		private IUserManager $userManager,
		private IL10N $l,
		private ContentLanguage $language,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/rules')]
	public function listRules(): JSONResponse {
		// with display path; path = null means: folder no longer exists (rule orphaned, has no effect)
		return new JSONResponse(array_map(fn ($rule) => $rule->toArray($this->l) + [
			'path' => $rule->getFolderId() === null ? null : $this->tree->displayPath((int)$rule->getFolderId()),
		], $this->rules->list()));
	}

	/**
	 * A shorter retention period deletes starting the next night – hence password confirmation, as for the simulation switch.
	 *
	 * @param string $folderId folder ID, "default" or "personal" (default rule for personal folders)
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/rules/{folderId}')]
	public function putRule(string $folderId, ?string $periodUnit = null, mixed $periodValue = null, ?string $scope = null, ?string $basis = null, mixed $notify = false): JSONResponse {
		$personal = $folderId === self::PERSONAL;
		$id = $personal ? null : $this->parseFolderId($folderId);
		if ($id === false) {
			return new JSONResponse(['message' => $this->l->t('Invalid folder ID')], Http::STATUS_BAD_REQUEST);
		}
		if ($id !== null) {
			if (!$this->roots->isManagedFolder($id)) {
				return new JSONResponse(['message' => $this->l->t('Folder not found or outside the managed areas')], Http::STATUS_NOT_FOUND);
			}
		}
		try {
			$rule = $this->rules->upsert($id, [
				'periodUnit' => $periodUnit,
				'periodValue' => $periodValue,
				'scope' => $scope,
				'basis' => $basis,
				'notify' => $notify,
			], $this->userSession->getUser()?->getUID(), $personal);
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->queueTagSync($id);
		return new JSONResponse($rule->toArray($this->l));
	}

	/**
	 * Without its own rule the folder inherits – possibly a shorter retention period. Hence password confirmation.
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/rules/{folderId}')]
	public function deleteRule(string $folderId): JSONResponse {
		$id = $folderId === self::PERSONAL ? null : $this->parseFolderId($folderId);
		if ($id === null) {
			return new JSONResponse(['message' => $this->l->t('Default rules cannot be removed')], Http::STATUS_BAD_REQUEST);
		}
		if ($id === false) {
			return new JSONResponse(['message' => $this->l->t('Invalid folder ID')], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->rules->delete($id)) {
			return new JSONResponse(['message' => $this->l->t('No rule on this folder')], Http::STATUS_NOT_FOUND);
		}
		$this->queueTagSync($id);
		return new JSONResponse(['deleted' => true]);
	}

	/**
	 * Without parent: scope roots. With parent: its subfolders.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/tree')]
	public function tree(?int $parent = null): JSONResponse {
		if ($parent === null) {
			return new JSONResponse(['nodes' => $this->tree->roots()]);
		}
		$nodes = $this->tree->children($parent);
		if ($nodes === null) {
			return new JSONResponse(['message' => $this->l->t('Folder not found')], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse(['nodes' => $nodes]);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/folders/{folderId}/descendants')]
	public function descendants(int $folderId): JSONResponse {
		$result = $this->tree->descendants($folderId);
		if ($result === null) {
			return new JSONResponse(['message' => $this->l->t('Folder not found')], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($result);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/folders/{folderId}/impact')]
	public function impact(int $folderId): JSONResponse {
		$impact = $this->tree->impact($folderId);
		if ($impact === null) {
			return new JSONResponse(['message' => $this->l->t('Folder not found')], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse($impact);
	}

	/**
	 * @param string $folderId folder ID, "default" or "personal" (= all files under the respective default rule),
	 *                         "all" (= every file of every area)
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/preview')]
	public function preview(string $folderId = 'default', int $days = 7): JSONResponse {
		$personal = $folderId === self::PERSONAL;
		$all = $folderId === self::ALL;
		$id = $personal || $all ? null : $this->parseFolderId($folderId);
		if ($id === false) {
			return new JSONResponse(['message' => $this->l->t('Invalid folder ID')], Http::STATUS_BAD_REQUEST);
		}
		$days = max(0, min(366, $days));
		$result = $this->runner->preview($id, $days, $all ? self::UPCOMING_LIMIT : self::PREVIEW_LIMIT, self::PREVIEW_BUDGET, $personal, $all);
		$result['days'] = $days;
		return new JSONResponse($result);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/log')]
	public function log(int $limit = 50, int $offset = 0, ?string $mode = null, ?string $status = null, ?string $search = null, ?int $from = null, ?int $to = null, ?string $folder = null, ?string $root = null, ?bool $superseded = null): JSONResponse {
		$limit = max(1, min(500, $limit));
		$offset = max(0, $offset);
		$filter = $this->logFilter($mode, $status, $search, $from, $to, $folder, $root, $superseded);
		return new JSONResponse([
			'entries' => $this->logMapper->findPage($limit, $offset, $filter),
			'total' => $this->logMapper->count($filter),
			'limit' => $limit,
			'offset' => $offset,
		]);
	}

	/**
	 * Overview: days with entries (newest first) including counts per status group.
	 * Same filters as /api/log except folder.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/log/days')]
	public function logDays(int $limit = 10, int $offset = 0, ?string $mode = null, ?string $status = null, ?string $search = null, ?int $from = null, ?int $to = null): JSONResponse {
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		return new JSONResponse($this->logSummary->days($this->logFilter($mode, $status, $search, $from, $to), $limit, $offset) + [
			'limit' => $limit,
			'offset' => $offset,
		]);
	}

	/**
	 * Overview: folders with entries including counts for a time span, usually one day.
	 * from and to are required and at most LOG_FOLDERS_SPAN apart – the count reads
	 * every matching row; without a limit that would be the entire log.
	 * The files of a folder are returned by /api/log with folder=<folder>.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/log/folders')]
	public function logFolders(?string $mode = null, ?string $status = null, ?string $search = null, ?int $from = null, ?int $to = null): JSONResponse {
		if ($from === null || $to === null || $from <= 0 || $to < $from || $to - $from > self::LOG_FOLDERS_SPAN) {
			return new JSONResponse(['message' => $this->l->t('Invalid time range')], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['folders' => $this->logSummary->folders($this->logFilter($mode, $status, $search, $from, $to))]);
	}

	/**
	 * Log as CSV (semicolon, UTF-8 with BOM – opens correctly in Excel right away).
	 * Same filters as /api/log except folder. Streamed: rows are read in chunks and sent in
	 * blocks, so even a large log never sits in memory as a whole.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/log/export')]
	public function exportLog(?string $mode = null, ?string $status = null, ?string $search = null, ?int $from = null, ?int $to = null): StreamTraversableResponse {
		$tz = $this->settings->timezone();
		$name = $this->l->t('folder-retention-log') . '-' . (new \DateTimeImmutable('now', $tz))->format('Y-m-d') . '.csv';
		return new StreamTraversableResponse($this->csvBlocks($this->logFilter($mode, $status, $search, $from, $to), $tz), Http::STATUS_OK, [
			'Content-Type' => 'text/csv; charset=utf-8',
			'Content-Disposition' => self::attachment($name),
		]);
	}

	/**
	 * CSV in blocks of up to 500 rows; the header line comes first.
	 *
	 * @param array<string, mixed> $filter
	 * @return \Generator<string>
	 */
	private function csvBlocks(array $filter, \DateTimeZone $tz): \Generator {
		$l = $this->l;
		$fmt = fn (?int $ts) => $ts ? (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s') : '';
		$statusLabels = [
			'deleted' => $l->t('deleted'),
			'would_delete' => $l->t('would delete'),
			'superseded' => $l->t('would delete (superseded)'),
			'skipped_locked' => $l->t('skipped (locked)'),
			'skipped_changed' => $l->t('skipped (changed)'),
			'error' => $l->t('error'),
			'deleted_final' => $l->t('permanently deleted (trash bin bypassed)'),
		];
		// defuse cells that Excel would read as a formula (file names like "=HYPERLINK(…)") with '
		$cell = fn ($v) => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
		$buffer = fopen('php://memory', 'r+');
		$flush = static function () use ($buffer): string {
			rewind($buffer);
			$block = (string)stream_get_contents($buffer);
			ftruncate($buffer, 0);
			rewind($buffer);
			return $block;
		};
		try {
			fwrite($buffer, "\xEF\xBB\xBF");
			fputcsv($buffer, [$l->t('Time'), $l->t('Mode'), $l->t('Status'), $l->t('File'), $l->t('Rule'), $l->t('Rule ID'), $l->t('Reference date'), $l->t('Reference date from'), $l->t('Message'), $l->t('File ID'), $l->t('Superseded')], ';', '"', '');
			$rows = 0;
			foreach ($this->logMapper->iterate($filter) as $e) {
				fputcsv($buffer, array_map($cell, [
					$fmt($e->getDeletedAt()),
					$e->getMode() === 'real' ? $l->t('real') : $l->t('Simulation'),
					$statusLabels[$e->getSupersededAt() !== null && $e->getStatus() === 'would_delete' ? 'superseded' : $e->getStatus()] ?? $e->getStatus(),
					$e->getPath(),
					$e->getRuleLabel(),
					$e->getRuleId(),
					$fmt($e->getReferenceDate()),
					$e->getReferenceSource(),
					$e->getMessage(),
					$e->getFileId(),
					$fmt($e->getSupersededAt()),
				]), ';', '"', '');
				if (++$rows % 500 === 0) {
					yield $flush();
				}
			}
			yield $flush();
		} finally {
			fclose($buffer);
		}
	}

	/** Content-Disposition for a download: ASCII fallback plus the UTF-8 name (RFC 6266) */
	private static function attachment(string $name): string {
		$name = str_replace(['/', '\\', '"', "\r", "\n"], '-', $name);
		$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'log.csv';
		return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
	}

	/**
	 * folder: null = all folders, '' = paths without a folder (query parameter "folder=")
	 * root: area key of the folder group (/api/log/folders), '' = older entries without a key
	 * superseded: the group's flag – superseded entries are a group of their own
	 *
	 * @return array{mode: ?string, status: ?string, search: ?string, from: ?int, to: ?int, folder?: string, root?: string, superseded?: bool}
	 */
	private function logFilter(?string $mode, ?string $status, ?string $search, ?int $from, ?int $to, ?string $folder = null, ?string $root = null, ?bool $superseded = null): array {
		$search = trim((string)$search);
		// root only together with folder (a group of /api/log/folders); a malformed key is ignored
		$root = $folder !== null && $root !== null && ($root === '' || preg_match('/^\d{10}:\d{12}$/', $root) === 1) ? $root : null;
		return ($folder === null ? [] : ['folder' => mb_substr($folder, 0, 4000)])
			+ ($root === null ? [] : ['root' => $root])
			+ ($folder === null || $superseded === null ? [] : ['superseded' => $superseded]) + [
			'mode' => in_array($mode, ['real', 'simulation'], true) ? $mode : null,
			'status' => in_array($status, LogSummary::CATEGORIES, true) ? $status : null,
			'search' => $search === '' ? null : mb_substr($search, 0, 200),
			'from' => $from !== null && $from > 0 ? $from : null,
			'to' => $to !== null && $to > 0 ? $to : null,
		];
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/settings')]
	public function getSettings(): JSONResponse {
		return new JSONResponse($this->settingsPayload());
	}

	/**
	 * Switching off simulation mode triggers real deletions – hence password confirmation.
	 * unblock = list of displayed blocks ({key, at} or just the key): lifts exactly these,
	 * all others stay – including ones added since the page was loaded.
	 * deletionLimit: 0 = none; resumeDeletion: continue after the limit was reached.
	 * tagLanguage: language of tag names and log texts, or "neutral" (ContentLanguage::choices()).
	 * filesInfo: deletion date as badge and sidebar tab in the Files app.
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings')]
	public function putSettings(?bool $simulation = null, ?bool $tags = null, mixed $unblock = null, ?int $logRetentionDays = null, ?int $deletionLimit = null, ?bool $resumeDeletion = null, ?string $tagLanguage = null, ?bool $filesInfo = null): JSONResponse {
		if ($deletionLimit !== null && ($deletionLimit < 0 || $deletionLimit > Settings::DELETION_LIMIT_MAX)) {
			return new JSONResponse(['message' => $this->l->t('Invalid deletion limit')], Http::STATUS_BAD_REQUEST);
		}
		if ($logRetentionDays !== null && !in_array($logRetentionDays, Settings::LOG_RETENTION_CHOICES, true)) {
			return new JSONResponse(['message' => $this->l->t('Invalid log retention')], Http::STATUS_BAD_REQUEST);
		}
		if ($tagLanguage !== null && !in_array($tagLanguage, $this->language->choices(), true)) {
			return new JSONResponse(['message' => $this->l->t('Invalid tag language')], Http::STATUS_BAD_REQUEST);
		}
		$unblockKeys = null;
		if ($unblock !== null) {
			$unblockKeys = $this->parseUnblock($unblock);
			if ($unblockKeys === null) {
				return new JSONResponse(['message' => $this->l->t('unblock expects a list of the locks to lift ({key, at}) – please reload the page')], Http::STATUS_BAD_REQUEST);
			}
		}
		if ($simulation !== null) {
			$this->settings->setSimulation($simulation);
		}
		if ($unblockKeys !== null) {
			$this->settings->unblockRoots($unblockKeys);
		}
		if ($logRetentionDays !== null) {
			$this->settings->setLogRetentionDays($logRetentionDays);
		}
		if ($deletionLimit !== null) {
			$this->settings->setDeletionLimit($deletionLimit);
		}
		if ($resumeDeletion === true) {
			$this->settings->resumeDeletion();
		}
		if ($filesInfo !== null) {
			$this->settings->setFilesInfoEnabled($filesInfo);
		}
		if ($tagLanguage !== null && $tagLanguage !== $this->language->choice()) {
			$this->language->choose($tagLanguage);
			// every file moves to the tag with the new name; the old tags are deleted afterwards
			$this->queueTagSync(null);
		}
		if ($tags !== null && $tags !== $this->settings->tagsEnabled()) {
			$this->settings->setTagsEnabled($tags);
			if ($tags) {
				$this->queueTagSync(null);
			} else {
				$this->tags->removeAll();
			}
		}
		return new JSONResponse($this->settingsPayload());
	}

	/**
	 * Mark an account as a workspace account (default rule applies) or treat it as personal again.
	 * Can trigger deletions – hence password confirmation.
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/accounts/{uid}/workspace')]
	public function setWorkspace(string $uid, bool $workspace): JSONResponse {
		if ($this->userManager->get($uid) === null) {
			return new JSONResponse(['message' => $this->l->t('Account not found')], Http::STATUS_NOT_FOUND);
		}
		if ($workspace !== $this->settings->isWorkspaceAccount($uid)) {
			$this->settings->setWorkspaceAccount($uid, $workspace);
			$this->roots->reset();
			$this->queueTagSync(null);
		}
		return new JSONResponse($this->settingsPayload());
	}

	/**
	 * @return array<string, ?int>|null key → displayed timestamp; null = invalid
	 */
	private function parseUnblock(mixed $unblock): ?array {
		if (!is_array($unblock) || !array_is_list($unblock)) {
			return null;
		}
		$keys = [];
		foreach ($unblock as $item) {
			if (is_string($item) && $item !== '') {
				$keys[$item] = null;
			} elseif (is_array($item) && is_string($item['key'] ?? null) && $item['key'] !== '') {
				$at = $item['at'] ?? null;
				if ($at !== null && !is_int($at) && !(is_string($at) && ctype_digit($at))) {
					return null;
				}
				$keys[$item['key']] = $at === null ? null : (int)$at;
			} else {
				return null;
			}
		}
		return $keys;
	}

	/** Update the tags of the affected subtree in the background (null = everything) */
	private function queueTagSync(?int $folderId): void {
		if ($this->settings->tagsEnabled()) {
			$this->jobList->add(TagSyncJob::class, ['folderId' => $folderId]);
		}
	}

	private function settingsPayload(): array {
		$blocked = [];
		foreach ($this->settings->blockedRoots() as $key => $b) {
			$blocked[] = ['key' => $key] + $b;
		}
		return [
			'simulation' => $this->settings->isSimulation(),
			'tags' => $this->settings->tagsEnabled(),
			// language of tag names and log texts, or "neutral"
			'tagLanguage' => $this->language->choice(),
			'tagLanguageChoices' => $this->language->choices(),
			'filesInfo' => $this->settings->filesInfoEnabled(),
			'logRetentionDays' => $this->settings->logRetentionDays(),
			'logRetentionChoices' => Settings::LOG_RETENTION_CHOICES,
			// 0 = no limit; deletionHalt set = limit reached, nothing is deleted until resumed
			'deletionLimit' => $this->settings->deletionLimit(),
			'deletionHalt' => $this->settings->deletionHalt(),
			'workspaceAccounts' => $this->settings->workspaceAccounts(),
			'tagSyncPending' => $this->jobList->has(TagSyncJob::class, ['folderId' => null]),
			'lastCycleCompleted' => $this->settings->lastCycleCompleted() ?: null,
			'cycleInProgress' => $this->settings->getCursor() !== null,
			'timezone' => $this->settings->timezone()->getName(),
			// cron = system cron; ajax/webcron: the app does not delete (see RetentionRunner::runScheduled)
			'cronMode' => $this->settings->backgroundJobsMode(),
			// scopes in which nothing more is deleted after a permanent deletion
			'blockedRoots' => $blocked,
		];
	}

	/** @return int|null|false null = default rule, false = invalid */
	private function parseFolderId(string $folderId): int|null|false {
		if ($folderId === 'default') {
			return null;
		}
		return ctype_digit($folderId) && (int)$folderId > 0 ? (int)$folderId : false;
	}
}
