<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\FixedL10N;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../stubs/Doctrine.php';

/**
 * tag_language: decided once, German for installs from the German-only era (tag names must stay
 * byte-identical), otherwise the instance default language or English. Never changed afterwards.
 */
class ContentLanguageTest extends TestCase {
	/** @var array<string, string> folder_retention app config */
	private array $app = [];
	private string $defaultLanguage = '';
	private bool $legacyTags = false;
	private bool $rules = false;
	private int $writes = 0;

	private function language(): ContentLanguage {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->app[$key] ?? $default);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) {
			$this->app[$key] = $value;
			$this->writes++;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key) {
			unset($this->app[$key]);
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(fn (string $key, string $default = '') => $key === 'default_language' && $this->defaultLanguage !== '' ? $this->defaultLanguage : $default);
		$test = $this;
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn(\OCA\FolderRetention\Tests\Unit\FakeL10N::en());
		return new class($appConfig, $config, $factory, $this->createMock(IDBConnection::class), $test) extends ContentLanguage {
			public function __construct(IAppConfig $a, IConfig $c, IFactory $f, IDBConnection $d, private ContentLanguageTest $test) {
				parent::__construct($a, $c, $f, $d);
			}
			protected function legacyTagsExist(): bool {
				return $this->test->flag('legacyTags');
			}
			protected function rulesExist(): bool {
				return $this->test->flag('rules');
			}
		};
	}

	public function flag(string $name): bool {
		return $this->$name;
	}

	public function testFreshInstallWithoutDefaultLanguageIsEnglish(): void {
		$this->assertSame('en', $this->language()->initialize());
		$this->assertSame('en', $this->app[ContentLanguage::CONFIG_KEY]);
	}

	public function testFreshInstallUsesInstanceDefaultLanguage(): void {
		$this->defaultLanguage = 'de';
		$this->assertSame('de', $this->language()->initialize());
	}

	public function testDefaultLanguageWithRegionUsesShippedLanguage(): void {
		// "de_DE" has no l10n file; Nextcloud would fall back to the triggering user's language
		$this->defaultLanguage = 'de_DE';
		$this->assertSame('de', $this->language()->initialize());
		$this->assertSame('de', $this->app[ContentLanguage::CONFIG_KEY]);
	}

	public function testUnsupportedDefaultLanguageIsEnglish(): void {
		$this->defaultLanguage = 'fr';
		$this->assertSame('en', $this->language()->initialize());
	}

	public function testUnsupportedStoredLanguageIsUsedAsEnglishWithoutRewrite(): void {
		$this->app[ContentLanguage::CONFIG_KEY] = 'fr';
		$this->assertSame('en', $this->language()->code());
		$this->app[ContentLanguage::CONFIG_KEY] = 'de_AT';
		$this->assertSame('de', $this->language()->code());
		$this->assertSame(0, $this->writes);
	}

	public function testExistingGermanTagsKeepGerman(): void {
		// production: default_language unset, „Aufbewahrung: …“ tags exist
		$this->legacyTags = true;
		$this->assertSame('de', $this->language()->initialize());
	}

	public function testGermanTagsWinOverDefaultLanguage(): void {
		$this->legacyTags = true;
		$this->defaultLanguage = 'en';
		$this->assertSame('de', $this->language()->initialize());
	}

	public function testUpdateFromVersionBeforeTranslationsIsGerman(): void {
		$this->app['installed_version'] = '0.8.1';
		$this->assertSame('de', $this->language()->initialize());
	}

	public function testExistingRulesMarkGermanEraInstall(): void {
		$this->rules = true;
		$this->assertSame('de', $this->language()->initialize());
	}

	public function testInstalledVersionWithTranslationsIsNotLegacy(): void {
		$this->app['installed_version'] = ContentLanguage::FIRST_L10N_VERSION;
		$this->assertSame('en', $this->language()->initialize());
	}

	public function testStoredLanguageIsNeverChangedAutomatically(): void {
		$this->app[ContentLanguage::CONFIG_KEY] = 'en';
		$this->legacyTags = true;
		$this->app['installed_version'] = '0.8.1';
		$language = $this->language();
		$this->assertSame('en', $language->initialize());
		$this->assertSame('en', $language->code());
		$this->assertSame(0, $this->writes);
	}

	/** The settings offer English, every shipped translation and neutral tags */
	public function testChoicesListShippedLanguagesAndNeutral(): void {
		$this->assertSame(['de', 'en', ContentLanguage::NEUTRAL], $this->language()->choices());
	}

	public function testChooseLanguage(): void {
		$this->app[ContentLanguage::CONFIG_KEY] = 'de';
		$language = $this->language();
		$this->assertSame('de', $language->choice());
		$language->choose('en');
		$this->assertSame('en', $language->choice());
		$this->assertSame('en', $language->code(), 'cached code is reset');
		$this->assertSame('en', $this->app[ContentLanguage::CONFIG_KEY]);
		$this->assertFalse($language->neutralTags());
	}

	/** Neutral: tags without words, texts in English; choosing a language again ends it */
	public function testChooseNeutralAndBack(): void {
		$this->app[ContentLanguage::CONFIG_KEY] = 'de';
		$language = $this->language();
		$language->choose(ContentLanguage::NEUTRAL);
		$this->assertTrue($language->neutralTags());
		$this->assertSame(ContentLanguage::NEUTRAL, $language->choice());
		$this->assertSame('en', $language->code());
		$language->choose('de');
		$this->assertFalse($language->neutralTags());
		$this->assertArrayNotHasKey(ContentLanguage::FORMAT_KEY, $this->app);
		$this->assertSame('de', $language->choice());
	}

	public function testChooseRejectsUnknownLanguage(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->language()->choose('fr');
	}

	public function testCodeDeterminesOnceWhenUnset(): void {
		$this->legacyTags = true;
		$language = $this->language();
		$this->assertSame('de', $language->code());
		$this->legacyTags = false;
		$this->defaultLanguage = 'en';
		$this->assertSame('de', $language->code());
		$this->assertSame('de', $this->language()->code(), 'a new process reads the stored value');
		$this->assertSame(1, $this->writes);
	}

	public function testL10nUsesStoredLanguageFromTheAppsOwnFile(): void {
		$this->app[ContentLanguage::CONFIG_KEY] = 'de';
		// IFactory::get() would honour ?forceLanguage= / force_language – it is only used for l()
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('folder_retention', 'en')->willReturn(\OCA\FolderRetention\Tests\Unit\FakeL10N::en());
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => $this->app[$key] ?? $default);
		$language = new ContentLanguage($appConfig, $this->createMock(IConfig::class), $factory, $this->createMock(IDBConnection::class));
		$l = $language->l10n();
		$this->assertSame('de', $l->getLanguageCode());
		$this->assertSame('Aufbewahrung: x', $l->t('Retention: %s', ['x']));
		$this->assertSame('2 Wochen', $l->n('%n week', '%n weeks', 2));
		$this->assertSame('1 Woche', $l->n('%n week', '%n weeks', 1));
		$this->assertSame($l, $language->l10n(), 'cached');
		$this->assertSame('Retention: x', $language->english()->t('Retention: %s', ['x']));
	}

	public function testMistypedStoredLanguageIsNormalised(): void {
		foreach (['DE', ' de ', "de\n", 'De', 'de-de', 'DE_de'] as $value) {
			$this->app[ContentLanguage::CONFIG_KEY] = $value;
			$language = $this->language();
			$this->assertSame('de', $language->code(), var_export($value, true));
			$this->assertTrue($language->reliable(), var_export($value, true));
		}
		$this->assertSame(0, $this->writes);
	}

	public function testUnknownStoredLanguageIsFlaggedUnreliable(): void {
		foreach (['fr', 'xx', '../l10n/de', 'php-de'] as $value) {
			$this->app[ContentLanguage::CONFIG_KEY] = $value;
			$language = $this->language();
			$this->assertSame('en', $language->code(), $value);
			$this->assertFalse($language->reliable(), $value);
		}
		$this->app[ContentLanguage::CONFIG_KEY] = 'en';
		$this->assertTrue($this->language()->reliable());
	}

	public function testBrokenTranslationNeverThrows(): void {
		$l = new FixedL10N('de', [
			'Retention: %s' => 'Aufbewahrung: %s %s',
			'_%n week_::_%n weeks_' => ['%n Woche %s', '%n Wochen %s'],
		], \OCA\FolderRetention\Tests\Unit\FakeL10N::en());
		$this->assertSame('Retention: x', $l->t('Retention: %s', ['x']), 'falls back to the source text');
		$this->assertSame('3 weeks', $l->n('%n week', '%n weeks', 3));
		$this->assertSame('Moved %s to %s ["a"]', $l->t('Moved %s to %s', ['a']), 'even a broken source text renders');
		$this->assertSame('Untranslated x', $l->t('Untranslated %s', ['x']));
	}
}
