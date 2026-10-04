<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Migration;

use OCA\FolderRetention\Migration\InstallDefaults;
use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\FileCacheReader;
use OCA\FolderRetention\Service\FirstSeen;
use OCA\FolderRetention\Service\RuleService;
use OCA\FolderRetention\Service\Settings;
use OCA\FolderRetention\Tests\Unit\FakeL10N;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Grenze Bestand/neu beim Update: Vom frühen 0.8.0-Stand (seen_since = Zeitpunkt) wird sie aus
 * „zuerst gesehen“ abgeleitet, nicht aus der höchsten Datei-ID beim Update – sonst zählten Kopien
 * aus der 0.8.0-Zeit als Bestand und wären sofort fällig (Harness S24).
 */
class InstallDefaultsTest extends TestCase {
	private ?int $mark = null;
	private ?int $legacy = null;
	private ?int $lastSeen = null;
	private int $maxFileId = 500;
	/** @var list<string> Aufrufe in Reihenfolge */
	private array $calls = [];
	/** @var list<string> language vs. default rules */
	private array $order = [];

	private function runStep(): void {
		$settings = $this->createMock(Settings::class);
		$settings->method('seenMaxFileId')->willReturnCallback(fn () => $this->mark);
		$settings->method('legacySeenSince')->willReturnCallback(fn () => $this->legacy);
		$settings->method('initSeenMaxFileId')->willReturnCallback(function (int $id) {
			$this->calls[] = "init:$id";
			$this->mark ??= max(1, $id);
		});
		$settings->method('dropLegacySeenSince')->willReturnCallback(function () {
			$this->calls[] = 'drop';
			$this->legacy = null;
		});
		$firstSeen = $this->createMock(FirstSeen::class);
		$firstSeen->method('lastIdSeenUntil')->willReturnCallback(function (int $ts) {
			$this->calls[] = "seen:$ts";
			return $this->lastSeen;
		});
		$fileCache = $this->createMock(FileCacheReader::class);
		$fileCache->method('maxFileId')->willReturnCallback(fn () => $this->maxFileId);
		$rules = $this->createMock(RuleService::class);
		$rules->method('ensureDefault')->willReturnCallback(function () {
			$this->order[] = 'rules';
			return new \OCA\FolderRetention\Db\Rule();
		});
		$language = $this->createMock(ContentLanguage::class);
		$language->method('initialize')->willReturnCallback(function () {
			$this->order[] = 'language';
			return 'de';
		});
		$step = new InstallDefaults($settings, $rules, $fileCache, $firstSeen, $language, FakeL10N::de());
		$step->run($this->createMock(IOutput::class));
	}

	public function testUpdateFromSeenSinceDerivesMarkFromFirstSeen(): void {
		$this->legacy = 1700000000;
		$this->lastSeen = 42;
		$this->runStep();
		$this->assertSame(['seen:1700000000', 'init:42', 'drop'], $this->calls, 'Grenze aus dem alten Stand, erst danach seen_since löschen');
		$this->assertSame(42, $this->mark);
		$this->assertNull($this->legacy);
	}

	public function testUpdateFromSeenSinceWithEmptySeenTableFallsBackToMaxFileId(): void {
		$this->legacy = 1700000000;
		$this->runStep();
		$this->assertSame(['seen:1700000000', 'init:500', 'drop'], $this->calls);
	}

	public function testFreshInstallOrOlderStateUsesMaxFileId(): void {
		$this->runStep();
		$this->assertSame(['init:500', 'drop'], $this->calls);
		$this->assertSame(500, $this->mark);
	}

	public function testLanguageIsFixedBeforeDefaultRulesAreCreated(): void {
		$this->runStep();
		// existing rules mark a German-era install – so the language must be decided first
		$this->assertSame(['language', 'rules'], $this->order);
	}

	public function testExistingMarkIsKept(): void {
		$this->mark = 7;
		$this->legacy = 1700000000;
		$this->lastSeen = 42;
		$this->runStep();
		$this->assertSame(['drop'], $this->calls, 'eine gesetzte Grenze bleibt – ein späterer Wert ließe Kopien als Bestand gelten');
		$this->assertSame(7, $this->mark);
	}
}
