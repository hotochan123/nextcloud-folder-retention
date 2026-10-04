<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Controller;

use OCA\FolderRetention\AppInfo\Application;
use OCA\FolderRetention\BackgroundJob\TagSyncJob;
use OCA\FolderRetention\Db\LogMapper;
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
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Admin-API. Keine #[NoAdminRequired]-Attribute → nur Administratoren.
 * Pfade relativ zu /apps/folder_retention.
 */
class ApiController extends Controller {
	private const PREVIEW_LIMIT = 500;
	private const PREVIEW_BUDGET = 20.0;
	/** längster Zeitraum für /api/log/folders */
	private const LOG_FOLDERS_SPAN = 31 * 86400;
	/** Pfadwert für die Standardregel persönlicher Ordner */
	private const PERSONAL = 'personal';

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
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/rules')]
	public function listRules(): JSONResponse {
		// Mit Anzeigepfad; path = null heißt: Ordner existiert nicht mehr (Regel verwaist, wirkungslos)
		return new JSONResponse(array_map(fn ($rule) => $rule->toArray($this->l) + [
			'path' => $rule->getFolderId() === null ? null : $this->tree->displayPath((int)$rule->getFolderId()),
		], $this->rules->list()));
	}

	/**
	 * Eine kürzere Frist löscht ab der nächsten Nacht – daher Passwortbestätigung wie beim Simulationsschalter.
	 *
	 * @param string $folderId Ordner-ID, "default" oder "personal" (Standardregel persönlicher Ordner)
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
	 * Ohne eigene Regel erbt der Ordner – womöglich eine kürzere Frist. Daher Passwortbestätigung.
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
	 * Ohne parent: Bereichswurzeln. Mit parent: dessen Unterordner.
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
	 * @param string $folderId Ordner-ID, "default" oder "personal" (= alle Dateien unter der jeweiligen Standardregel)
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/preview')]
	public function preview(string $folderId = 'default', int $days = 7): JSONResponse {
		$personal = $folderId === self::PERSONAL;
		$id = $personal ? null : $this->parseFolderId($folderId);
		if ($id === false) {
			return new JSONResponse(['message' => $this->l->t('Invalid folder ID')], Http::STATUS_BAD_REQUEST);
		}
		$days = max(0, min(366, $days));
		$result = $this->runner->preview($id, $days, self::PREVIEW_LIMIT, self::PREVIEW_BUDGET, $personal);
		$result['days'] = $days;
		return new JSONResponse($result);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/log')]
	public function log(int $limit = 50, int $offset = 0, ?string $mode = null, ?string $status = null, ?string $search = null, ?int $from = null, ?int $to = null, ?string $folder = null): JSONResponse {
		$limit = max(1, min(500, $limit));
		$offset = max(0, $offset);
		$filter = $this->logFilter($mode, $status, $search, $from, $to, $folder);
		return new JSONResponse([
			'entries' => $this->logMapper->findPage($limit, $offset, $filter),
			'total' => $this->logMapper->count($filter),
			'limit' => $limit,
			'offset' => $offset,
		]);
	}

	/**
	 * Übersicht: Tage mit Einträgen (neueste zuerst) samt Zahlen je Statusgruppe.
	 * Gleiche Filter wie /api/log außer folder.
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
	 * Übersicht: Ordner mit Einträgen samt Zahlen für einen Zeitraum, meist einen Tag.
	 * from und to sind Pflicht und höchstens LOG_FOLDERS_SPAN auseinander – die Zählung liest
	 * jede passende Zeile, ohne Grenze wäre das das ganze Protokoll.
	 * Die Dateien eines Ordners liefert /api/log mit folder=<Ordner>.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/log/folders')]
	public function logFolders(?string $mode = null, ?string $status = null, ?string $search = null, ?int $from = null, ?int $to = null): JSONResponse {
		if ($from === null || $to === null || $from <= 0 || $to < $from || $to - $from > self::LOG_FOLDERS_SPAN) {
			return new JSONResponse(['message' => $this->l->t('Invalid time range')], Http::STATUS_BAD_REQUEST);
		}
		return new JSONResponse(['folders' => $this->logSummary->folders($this->logFilter($mode, $status, $search, $from, $to))]);
	}

	/**
	 * Protokoll als CSV (Semikolon, UTF-8 mit BOM – öffnet in Excel direkt richtig).
	 * Gleiche Filter wie /api/log außer folder.
	 */
	#[FrontpageRoute(verb: 'GET', url: '/api/log/export')]
	public function exportLog(?string $mode = null, ?string $status = null, ?string $search = null, ?int $from = null, ?int $to = null): DataDownloadResponse {
		$tz = $this->settings->timezone();
		$fmt = fn (?int $ts) => $ts ? (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s') : '';
		$out = fopen('php://temp', 'r+');
		fwrite($out, "\xEF\xBB\xBF");
		$l = $this->l;
		fputcsv($out, [$l->t('Time'), $l->t('Mode'), $l->t('Status'), $l->t('File'), $l->t('Rule'), $l->t('Rule ID'), $l->t('Reference date'), $l->t('Reference date from'), $l->t('Message'), $l->t('File ID')], ';', '"', '');
		$statusLabels = [
			'deleted' => $l->t('deleted'),
			'would_delete' => $l->t('would delete'),
			'skipped_locked' => $l->t('skipped (locked)'),
			'skipped_changed' => $l->t('skipped (changed)'),
			'error' => $l->t('error'),
			'deleted_final' => $l->t('permanently deleted (trash bin bypassed)'),
		];
		// Zellen, die Excel als Formel läse (Dateinamen wie „=HYPERLINK(…)“), mit ' entschärfen
		$cell = fn ($v) => is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
		foreach ($this->logMapper->iterate($this->logFilter($mode, $status, $search, $from, $to)) as $e) {
			fputcsv($out, array_map($cell, [
				$fmt($e->getDeletedAt()),
				$e->getMode() === 'real' ? $l->t('real') : $l->t('Simulation'),
				$statusLabels[$e->getStatus()] ?? $e->getStatus(),
				$e->getPath(),
				$e->getRuleLabel(),
				$e->getRuleId(),
				$fmt($e->getReferenceDate()),
				$e->getReferenceSource(),
				$e->getMessage(),
				$e->getFileId(),
			]), ';', '"', '');
		}
		rewind($out);
		$csv = stream_get_contents($out);
		fclose($out);
		$name = $l->t('folder-retention-log') . '-' . (new \DateTimeImmutable('now', $tz))->format('Y-m-d') . '.csv';
		return new DataDownloadResponse($csv, $name, 'text/csv; charset=utf-8');
	}

	/**
	 * folder: null = alle Ordner, '' = Pfade ohne Ordner (Abfrageparameter „folder=“)
	 *
	 * @return array{mode: ?string, status: ?string, search: ?string, from: ?int, to: ?int, folder?: string}
	 */
	private function logFilter(?string $mode, ?string $status, ?string $search, ?int $from, ?int $to, ?string $folder = null): array {
		$search = trim((string)$search);
		return ($folder === null ? [] : ['folder' => mb_substr($folder, 0, 4000)]) + [
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
	 * Simulationsmodus ausschalten löst echte Löschungen aus – daher Passwortbestätigung.
	 * unblock = Liste angezeigter Sperren ({key, at} oder nur der Schlüssel): hebt genau diese auf,
	 * alle anderen bleiben – auch solche, die seit dem Laden der Seite hinzugekommen sind.
	 */
	#[PasswordConfirmationRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings')]
	public function putSettings(?bool $simulation = null, ?bool $tags = null, mixed $unblock = null): JSONResponse {
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
	 * Konto als Arbeitsbereich markieren (Standardregel gilt) oder wieder als persönlich behandeln.
	 * Kann Löschungen auslösen – daher Passwortbestätigung.
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
	 * @return array<string, ?int>|null Schlüssel → angezeigter Zeitpunkt; null = ungültig
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

	/** Tags des betroffenen Unterbaums im Hintergrund nachziehen (null = alles) */
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
			'workspaceAccounts' => $this->settings->workspaceAccounts(),
			'tagSyncPending' => $this->jobList->has(TagSyncJob::class, ['folderId' => null]),
			'lastCycleCompleted' => $this->settings->lastCycleCompleted() ?: null,
			'cycleInProgress' => $this->settings->getCursor() !== null,
			'timezone' => $this->settings->timezone()->getName(),
			// cron = System-Cron; ajax/webcron: die App löscht nicht (siehe RetentionRunner::runScheduled)
			'cronMode' => $this->settings->backgroundJobsMode(),
			// Bereiche, in denen nach einer endgültigen Löschung nichts mehr gelöscht wird
			'blockedRoots' => $blocked,
		];
	}

	/** @return int|null|false null = Standardregel, false = ungültig */
	private function parseFolderId(string $folderId): int|null|false {
		if ($folderId === 'default') {
			return null;
		}
		return ctype_digit($folderId) && (int)$folderId > 0 ? (int)$folderId : false;
	}
}
