<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\Migration;

use OCA\FolderRetention\Migration\SimulationOnDisable;
use OCA\FolderRetention\Service\Settings;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud does not delete the app config when the app is removed. Without this step a
 * reinstall would run live immediately with simulation mode off, using the old retention periods (harness S26).
 */
class SimulationOnDisableTest extends TestCase {
	public function testSwitchesSimulationOn(): void {
		$settings = $this->createMock(Settings::class);
		$settings->expects($this->once())->method('setSimulation')->with(true);
		(new SimulationOnDisable($settings, \OCA\FolderRetention\Tests\Unit\FakeL10N::de()))->run($this->createMock(IOutput::class));
	}

	public function testIsRegisteredAsUninstallStep(): void {
		// Nextcloud runs the uninstall steps on app:disable and app:remove (without --keep-data)
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($xml);
		$steps = array_map('strval', $xml->xpath('/info/repair-steps/uninstall/step') ?: []);
		$this->assertSame([SimulationOnDisable::class], $steps);
	}
}
