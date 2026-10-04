<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one fixed language of everything this app stores instance-wide: system tag names
 * („Retention: 2 weeks“), log texts (rule label, message), lock reasons and the labels of
 * personal areas. System tags are matched by name, so this language must never drift with
 * the language of whoever happens to trigger a run.
 *
 * Stored in app config `tag_language`. Set once (install / first update with this code,
 * see InstallDefaults) and never changed automatically afterwards:
 * - existing install from the German-only era (tags „Aufbewahrung: …“ exist, rules exist, or
 *   the installed version predates translations) → "de", so names stay byte-identical;
 * - fresh install → system default_language if the app ships it ("de_DE" → "de"), else "en".
 * An admin may change it deliberately: occ config:app:set folder_retention tag_language --value=en
 * (new tags are then created, the old ones are removed from files by the next run).
 */
class ContentLanguage {
	public const CONFIG_KEY = 'tag_language';
	/** Tag prefix of all versions before translations existed */
	public const LEGACY_TAG_PREFIX = 'Aufbewahrung: ';
	/** First version with translatable texts – anything installed before is German-era */
	public const FIRST_L10N_VERSION = '0.9.0';

	private ?IL10N $l10n = null;
	private ?string $code = null;
	/** Set when the configured language cannot be honoured (see reliable()) */
	private ?string $problem = null;
	/** @var array<string, array<string, string|list<string>>|null> loaded translation files */
	private array $files = [];

	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private IFactory $factory,
		private IDBConnection $db,
		private ?LoggerInterface $logger = null,
	) {
	}

	/**
	 * False when the stored language cannot be honoured (unknown code, missing or broken
	 * translation file): texts then come out in English. Tag sync must not run in that state,
	 * otherwise it would replace every tag with one in another language.
	 */
	public function reliable(): bool {
		$this->l10n();
		return $this->problem === null;
	}

	/** Language code of stored texts; determines and stores it on first use */
	public function code(): string {
		if ($this->code !== null) {
			return $this->code;
		}
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($stored === '') {
			return $this->initialize();
		}
		$code = $this->supported($stored);
		if ($code === 'en' && strtolower(trim($stored)) !== 'en') {
			$this->fail('content language "' . $stored . '" (app config ' . self::CONFIG_KEY . ') is not shipped with the app');
		}
		return $this->code = $code;
	}

	/**
	 * Stores the language if not set yet (idempotent, never overwrites).
	 *
	 * @return string the language now in effect
	 */
	public function initialize(): string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($stored !== '') {
			return $this->code = $this->supported($stored);
		}
		$lang = $this->detect();
		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $lang);
		$this->l10n = null;
		return $this->code = $lang;
	}

	/** Translator for stored texts – fixed language, immune to forceLanguage, never throws */
	public function l10n(): IL10N {
		return $this->l10n ??= $this->translator($this->code());
	}

	/** Untranslated source texts (English) – e.g. for summaries written to the server log */
	public function english(): IL10N {
		return $this->translator('en');
	}

	private function translator(string $lang): IL10N {
		$translations = [];
		if ($lang !== 'en') {
			$translations = $this->load($lang);
			if ($translations === null) {
				$this->fail('translation file l10n/' . $lang . '.json is missing or broken');
				$translations = [];
			}
		}
		return new FixedL10N($lang, $translations, $this->factory->get(Application::APP_ID, 'en'));
	}

	/**
	 * Translations of a shipped language, or null if missing, broken or with plural rules
	 * FixedL10N cannot render (it knows exactly two forms: n == 1 and everything else).
	 *
	 * @return array<string, string|list<string>>|null
	 */
	private function load(string $lang): ?array {
		if (array_key_exists($lang, $this->files)) {
			return $this->files[$lang];
		}
		$result = null;
		try {
			$file = $this->file($lang);
			if (is_file($file)) {
				$json = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
				$plural = preg_replace('/\s+/', '', (string)($json['pluralForm'] ?? ''));
				if (is_array($json['translations'] ?? null) && in_array($plural, ['', 'nplurals=2;plural=(n!=1);'], true)) {
					$result = $json['translations'];
				}
			}
		} catch (Throwable) {
		}
		return $this->files[$lang] = $result;
	}

	private function fail(string $problem): void {
		$this->problem ??= $problem;
		$this->logger?->error('folder_retention: ' . $problem . ' – stored texts fall back to English, tag sync is paused');
	}

	private function file(string $lang): string {
		return dirname(__DIR__, 2) . '/l10n/' . $lang . '.json';
	}

	/**
	 * A language the app ships translations for: "de_DE" → "de", anything unknown → "en".
	 * Nextcloud would otherwise fall back to the language of whoever triggers the code.
	 */
	public function supported(string $code): string {
		$code = trim($code);
		$parts = preg_split('/[_-]/', $code, 2) ?: [$code];
		$language = strtolower($parts[0]);
		$candidates = isset($parts[1]) ? [$language . '_' . strtoupper($parts[1]), $language] : [$language];
		foreach ($candidates as $candidate) {
			if ($candidate === 'en') {
				return 'en';
			}
			if (preg_match('/^[a-z]{2,3}(_[A-Z]{2,4})?$/', $candidate) === 1 && $this->load($candidate) !== null) {
				return $candidate;
			}
		}
		return 'en';
	}

	private function detect(): string {
		if ($this->legacyTagsExist() || $this->installedBeforeL10n() || $this->rulesExist()) {
			return 'de';
		}
		$default = $this->config->getSystemValueString('default_language', '');
		return $default !== '' ? $this->supported($default) : 'en';
	}

	protected function legacyTagsExist(): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('systemtag')
				->where($qb->expr()->like('name', $qb->createNamedParameter($this->db->escapeLikeParameter(self::LEGACY_TAG_PREFIX) . '%')))
				->setMaxResults(1);
			return $qb->executeQuery()->fetchOne() !== false;
		} catch (Throwable) {
			return false;
		}
	}

	protected function installedBeforeL10n(): bool {
		try {
			$installed = $this->appConfig->getValueString(Application::APP_ID, 'installed_version', '');
		} catch (Throwable) {
			return false;
		}
		// empty = fresh install (Nextcloud sets installed_version after the install steps)
		return $installed !== '' && version_compare($installed, self::FIRST_L10N_VERSION, '<');
	}

	/** Rules exist before the install step created the defaults → install from an earlier version */
	protected function rulesExist(): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('folder_retention_rules')->setMaxResults(1);
			return $qb->executeQuery()->fetchOne() !== false;
		} catch (Throwable) {
			return false;
		}
	}
}
