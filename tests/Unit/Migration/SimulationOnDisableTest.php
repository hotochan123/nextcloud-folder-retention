<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Migration;

use OCA\FolderRetention\Migration\SimulationOnDisable;
use OCA\FolderRetention\Service\Settings;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud löscht beim Entfernen der App ihre App-Config nicht. Ohne diesen Schritt liefe eine
 * Neuinstallation mit ausgeschalteter Simulation nach den alten Fristen sofort scharf (Harness S26).
 */
class SimulationOnDisableTest extends TestCase {
	public function testSwitchesSimulationOn(): void {
		$settings = $this->createMock(Settings::class);
		$settings->expects($this->once())->method('setSimulation')->with(true);
		(new SimulationOnDisable($settings, \OCA\FolderRetention\Tests\Unit\FakeL10N::de()))->run($this->createMock(IOutput::class));
	}

	public function testIsRegisteredAsUninstallStep(): void {
		// Nextcloud führt die uninstall-Schritte bei app:disable und app:remove (ohne --keep-data) aus
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($xml);
		$steps = array_map('strval', $xml->xpath('/info/repair-steps/uninstall/step') ?: []);
		$this->assertSame([SimulationOnDisable::class], $steps);
	}
}
