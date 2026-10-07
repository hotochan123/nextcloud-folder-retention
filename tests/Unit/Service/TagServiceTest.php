<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Service;

use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\TagService;
use OCA\FolderRetention\Tests\Unit\FakeL10N;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\SystemTag\ISystemTagManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../../stubs/Doctrine.php';

class TagServiceTest extends TestCase {
	private function service(string $lang, bool $reliable = true, bool $neutral = false): TagService {
		$language = $this->createMock(ContentLanguage::class);
		$language->method('l10n')->willReturn(new FakeL10N($lang));
		$language->method('reliable')->willReturn($reliable);
		$language->method('neutralTags')->willReturn($neutral);
		return new TagService($this->createMock(IDBConnection::class), $this->createMock(IAppConfig::class), new NullLogger(), $language, $this->createMock(ISystemTagManager::class));
	}

	/** Neutral tags carry no words – one name that reads the same in every language */
	public function testNeutralLabels(): void {
		$tags = $this->service('de', true, true);
		$this->assertSame('⌛ 1 d', $tags->labelFor(Period::of(1, PeriodUnit::Day)));
		$this->assertSame('⌛ 2 w', $tags->labelFor(Period::of(2, PeriodUnit::Week)));
		$this->assertSame('⌛ 6 m', $tags->labelFor(Period::of(6, PeriodUnit::Month)));
		$this->assertSame('⌛ ∞', $tags->labelFor(Period::never()));
	}

	/** Without stale own tags there is nothing to look up or delete */
	public function testPruneWithoutRegistryDoesNothing(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->expects($this->never())->method('deleteTags');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{}');
		$tags = new TagService($this->createMock(IDBConnection::class), $appConfig, new NullLogger(), $this->createMock(ContentLanguage::class), $manager);
		$this->assertSame(0, $tags->pruneUnused(['Retention: 1 day']));
	}

	/** Unknown/broken content language: no tag in a fallback language, callers pause tag sync */
	public function testNoLabelWhenContentLanguageIsUnreliable(): void {
		$this->expectException(\RuntimeException::class);
		$this->service('en', false)->labelFor(Period::never());
	}

	/** System tags are matched by name: with tag_language=de the names must stay exactly as before */
	public function testGermanLabelsAreByteIdenticalToEarlierVersions(): void {
		$tags = $this->service('de');
		$this->assertSame('Aufbewahrung: 1 Tag', $tags->labelFor(Period::of(1, PeriodUnit::Day)));
		$this->assertSame('Aufbewahrung: 3 Tage', $tags->labelFor(Period::of(3, PeriodUnit::Day)));
		$this->assertSame('Aufbewahrung: 1 Woche', $tags->labelFor(Period::of(1, PeriodUnit::Week)));
		$this->assertSame('Aufbewahrung: 2 Wochen', $tags->labelFor(Period::of(2, PeriodUnit::Week)));
		$this->assertSame('Aufbewahrung: 1 Monat', $tags->labelFor(Period::of(1, PeriodUnit::Month)));
		$this->assertSame('Aufbewahrung: 6 Monate', $tags->labelFor(Period::of(6, PeriodUnit::Month)));
		$this->assertSame('Aufbewahrung: unbegrenzt', $tags->labelFor(Period::never()));
		$this->assertStringStartsWith(ContentLanguage::LEGACY_TAG_PREFIX, $tags->labelFor(Period::never()));
		$de = FakeL10N::de();
		$this->assertSame('Aufbewahrung: 2 Wochen (Ordnerregel)', $de->t('%s (folder rule)', ['Aufbewahrung: 2 Wochen']));
		$this->assertSame('Aufbewahrung: 2 Wochen (Ordnerregel 2)', $de->t('%s (folder rule 2)', ['Aufbewahrung: 2 Wochen']));
	}

	public function testEnglishLabels(): void {
		$tags = $this->service('en');
		$this->assertSame('Retention: 1 day', $tags->labelFor(Period::of(1, PeriodUnit::Day)));
		$this->assertSame('Retention: 2 weeks', $tags->labelFor(Period::of(2, PeriodUnit::Week)));
		$this->assertSame('Retention: 1 month', $tags->labelFor(Period::of(1, PeriodUnit::Month)));
		$this->assertSame('Retention: unlimited', $tags->labelFor(Period::never()));
	}

	public function testLongestLabelFitsTagColumn(): void {
		// Spalte oc_systemtag.name: 64 Zeichen, inkl. Ausweichnamen „… (Ordnerregel 2)“
		foreach (['de', 'en'] as $lang) {
			$l = new FakeL10N($lang);
			$label = $l->t('%s (folder rule 2)', [$this->service($lang)->labelFor(Period::of(Period::MAX_VALUE, PeriodUnit::Month))]);
			$this->assertLessThanOrEqual(64, mb_strlen($label), $label);
		}
	}
}
