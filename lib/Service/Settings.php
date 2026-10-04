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
 * Zentrale Stelle für App-Einstellungen (IAppConfig, App-ID folder_retention).
 *
 * Per occ änderbar, z. B.:
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
	/** Vorgänger der Grenze im frühen 0.8.0-Stand (fb4395c): Zeitpunkt statt Datei-ID */
	private const LEGACY_SEEN_SINCE = 'seen_since';
	private const LOCK_TABLE = 'folder_retention_lock';
	private const BLOCK_TABLE = 'folder_retention_block';
	private const RUN_LOCK = 'run';

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IDBConnection $db,
	) {
	}

	/** Simulationsmodus – Standard AN, solange nicht ausdrücklich ausgeschaltet */
	public function isSimulation(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, Application::CONFIG_SIMULATION, true);
	}

	/**
	 * Simulationsmodus, frisch aus der Datenbank (oc_appconfig) statt aus dem Prozess-Cache von
	 * IAppConfig: Ein laufender occ-Lauf bzw. Job soll die Notbremse „Simulation an“ (Oberfläche
	 * oder occ config:app:set) noch vor der nächsten Löschung sehen. Im Zweifel AN.
	 */
	public function isSimulationFresh(): bool {
		try {
			$raw = $this->loadSimulationValue();
		} catch (\Throwable) {
			return true;
		}
		if ($raw === null) {
			return true; // nie gesetzt: Standard AN
		}
		return !in_array(strtolower(trim($raw)), ['0', 'false', 'no', 'off'], true);
	}

	public function setSimulation(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, Application::CONFIG_SIMULATION, $on);
	}

	/** Bei Installation: Simulationsmodus explizit AN – aber nie eine bewusste Abschaltung überschreiben */
	public function initSimulation(): void {
		if (!$this->appConfig->hasKey(Application::APP_ID, Application::CONFIG_SIMULATION)) {
			$this->setSimulation(true);
		}
	}

	/**
	 * Alter Schalter „Standardregel auch für persönliche Dateien“ (bis 0.5). Wird nur noch einmalig
	 * ausgewertet, wenn die Standardregel für persönliche Ordner angelegt wird.
	 */
	public function includePersonal(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, Application::CONFIG_INCLUDE_PERSONAL, false);
	}

	public function setIncludePersonal(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, Application::CONFIG_INCLUDE_PERSONAL, $on);
	}

	/** Informative Aufbewahrungs-Tags an Dateien und Ordnern setzen? Standard AUS */
	public function tagsEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, Application::CONFIG_TAGS, false);
	}

	public function setTagsEnabled(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, Application::CONFIG_TAGS, $on);
	}

	/**
	 * Konten, deren Dateien als Arbeitsbereich gelten (Funktionskonten mit geteilten Ordnern):
	 * Für sie gilt die Standardregel, unabhängig von includePersonal().
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

	/** Sekunden pro Job-Ausführung */
	public function timeBudget(): int {
		return max(10, $this->appConfig->getValueInt(Application::APP_ID, self::TIME_BUDGET, 120));
	}

	public function batchSize(): int {
		return max(50, $this->appConfig->getValueInt(Application::APP_ID, self::BATCH_SIZE, 500));
	}

	/** Betriebsart der Hintergrundjobs: cron (System-Cron), ajax oder webcron */
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
	 * Cursor des laufenden Zyklus. Ältere Cursor (0.7.x, Vorstufe 0.8 mit „seen“) bleiben lesbar.
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
	 * App-Config neu aus der Datenbank lesen. IAppConfig hält die Werte je Prozess (im Web
	 * zusätzlich kurz in APCu); ein Hintergrundjob, der lange nach Prozessstart drankommt, sähe
	 * sonst Cursor und Zyklusende von vor seinem Start.
	 */
	public function refresh(): void {
		try {
			$this->appConfig->clearCache();
		} catch (\Throwable) {
			// ältere Nextcloud ohne clearCache – dann bleibt es beim Prozess-Stand
		}
	}

	public function lastCycleCompleted(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::LAST_CYCLE, 0);
	}

	public function setLastCycleCompleted(int $ts): void {
		$this->appConfig->setValueInt(Application::APP_ID, self::LAST_CYCLE, $ts);
	}

	/**
	 * Sperre gegen parallele Läufe (occ und Hintergrundjob): eine Zeile in folder_retention_lock
	 * mit Ablauf. Atomar: Neu angelegt wird per Einfügen ohne Überschreiben (Primärschlüssel),
	 * übernommen nur per UPDATE mit Bedingung „abgelaufen oder schon meine“ – die Datenbank
	 * lässt dabei genau einen Prozess gewinnen. Stürzt ein Lauf ab, läuft die Sperre nach
	 * $ttl Sekunden von selbst aus.
	 *
	 * @return array{holder: string, token: string, until: int}|null fremder Halter oder null = erhalten
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
			// Zeile verschwand zwischen den Schritten (Halter hat freigegeben) – noch einmal
		}
		return ['holder' => 'unbekannt', 'token' => '', 'until' => $now + $ttl];
	}

	/**
	 * Gehaltene Sperre verlängern.
	 *
	 * @return bool false = Sperre gehört nicht mehr diesem Lauf (abgelaufen und übernommen)
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
	 * Grenze zwischen Bestand und neu: höchste Datei-ID im Filecache bei Installation bzw. beim
	 * Update auf 0.8 (InstallDefaults). Dateien mit höherer ID sind danach entstanden – auch
	 * Kopien, die Upload- und Erstellzeit des Originals erben (Cache::copyFromCache), bekommen
	 * stets eine neue, höhere ID. Für sie zählt „zuerst gesehen“ neben der Upload-Zeit;
	 * Verschieben und Wiederherstellen behalten die ID. null = noch nicht gesetzt.
	 */
	public function seenMaxFileId(): ?int {
		$id = $this->appConfig->getValueInt(Application::APP_ID, self::SEEN_MAX_FILEID, 0);
		return $id > 0 ? $id : null;
	}

	/** Einmalig setzen – ein späterer Wert würde neue Dateien (Kopien) wieder als Bestand zählen */
	public function initSeenMaxFileId(int $maxFileId): void {
		if ($this->seenMaxFileId() === null) {
			// 0 bei leerem Filecache: dann gilt jede Datei als neu – kleinster gültiger Wert ist 1
			$this->appConfig->setValueInt(Application::APP_ID, self::SEEN_MAX_FILEID, max(1, $maxFileId));
		}
	}

	/**
	 * Grenze des frühen 0.8.0-Stands (fb4395c): Ende des ersten vollständigen Zyklus. Dort galt
	 * eine Datei als neu, wenn ihr „zuerst gesehen“ danach lag. Nur noch für den Wechsel auf
	 * seen_max_fileid gelesen (InstallDefaults). null = nicht gesetzt.
	 */
	public function legacySeenSince(): ?int {
		$ts = $this->appConfig->getValueInt(Application::APP_ID, self::LEGACY_SEEN_SINCE, 0);
		return $ts > 0 ? $ts : null;
	}

	/** Erst entfernen, wenn seen_max_fileid daraus abgeleitet ist */
	public function dropLegacySeenSince(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::LEGACY_SEEN_SINCE);
	}

	/**
	 * Bereiche, in denen nach einer endgültigen Löschung (Papierkorb umgangen) nichts mehr
	 * gelöscht wird, bis ein Admin die Sperre aufhebt.
	 *
	 * Eine Zeile je Bereich in folder_retention_block, jedes Mal frisch aus der Datenbank gelesen –
	 * nicht in der App-Config: Deren Prozess-Cache (im Web dazu APCu) ließe einen Lauf, der vor dem
	 * Setzen einer Sperre gestartet ist, im gesperrten Bereich weiterlöschen, und Lesen-Ändern-
	 * Schreiben einer gemeinsamen Liste verlöre Sperren anderer Prozesse.
	 *
	 * @return array<string, array{label: string, reason: string, at: int}> Bereichsschlüssel → Grund
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
	 * $rootKey = RetentionRoot::blockKey() (Storage + Wurzel, ohne Art). Ältere Einträge tragen
	 * noch die Art davor („home:…“, „workspace:…“) – sie gelten für denselben Bereich.
	 */
	public function isRootBlocked(string $rootKey): bool {
		foreach (array_keys($this->blockedRoots()) as $key) {
			if ($key === $rootKey || str_ends_with($key, ':' . $rootKey)) {
				return true;
			}
		}
		return false;
	}

	/** Sperre setzen; eine schon bestehende für den Bereich bleibt mit ihrem Grund und Zeitpunkt */
	public function blockRoot(string $rootKey, string $label, string $reason, int $at): void {
		$this->insertBlock($rootKey, mb_substr($label, 0, 255), mb_substr($reason, 0, 500), $at);
	}

	/**
	 * Nur die genannten Sperren aufheben – alle anderen (auch inzwischen neu hinzugekommene)
	 * bleiben. Mit Zeitpunkt ($at) nur, wenn die Sperre noch genau die angezeigte ist: Wurde sie
	 * zwischenzeitlich aufgehoben und neu gesetzt, bleibt die neue stehen. Je Sperre ein DELETE
	 * mit Bedingung – nichts wird aus einem älteren Stand zurückgeschrieben.
	 *
	 * @param array<string, ?int> $keys Bereichsschlüssel → angezeigter Zeitpunkt (null = egal)
	 * @return list<string> tatsächlich aufgehobene Schlüssel
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
	 * In der Vorstufe von 0.8.0 standen die Sperren als JSON in der App-Config (blocked_roots). Übernimmt sie in die
	 * Tabelle – beim Update (InstallDefaults) und vorsichtshalber bei jedem Zugriff, falls ein noch
	 * laufender alter Prozess eine Sperre dorthin geschrieben hat. Vor dem Übernehmen frisch lesen:
	 * Ein veralteter Prozess-Stand setzte sonst längst aufgehobene Sperren wieder.
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

	// Datenbankzugriffe, gekapselt (in Unit-Tests ersetzt)

	/** Rohwert von simulation_mode in oc_appconfig; null = kein Eintrag */
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

	/** Einfügen ohne Überschreiben (Primärschlüssel block_key) – atomar, auch bei parallelen Läufen */
	protected function insertBlock(string $key, string $label, string $reason, int $at): void {
		$this->db->insertIgnoreConflict(self::BLOCK_TABLE, [
			'block_key' => $key, 'label' => $label, 'reason' => $reason, 'blocked_at' => $at,
		]);
	}

	/** @return int Anzahl gelöschter Zeilen */
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
