<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud only runs app migrations (and InstallDefaults) when the version in
 * appinfo/info.xml differs from the installed one. A new migration without a version bump
 * would never run on instances with the older state – e.g. oc_folder_retention_block was missing after
 * fb4395c (0.8.0) → working tree, and every block check failed on the missing table.
 */
class VersionTest extends TestCase {
	/**
	 * Shipped or installed states: version → migrations it brought along.
	 * Add every new state with a new migration here.
	 *
	 * @var array<string, list<string>>
	 */
	private const SHIPPED = [
		'0.7.4' => ['Version1000Date20260925000000', 'Version1001Date20260928000000'],
		'0.8.0' => ['Version1000Date20260925000000', 'Version1001Date20260928000000', 'Version1002Date20261004000000'],
		'0.8.1' => ['Version1000Date20260925000000', 'Version1001Date20260928000000', 'Version1002Date20261004000000', 'Version1003Date20261004120000'],
		'0.8.2' => ['Version1000Date20260925000000', 'Version1001Date20260928000000', 'Version1002Date20261004000000', 'Version1003Date20261004120000'],
	];

	private static function appVersion(): string {
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		self::assertNotFalse($xml);
		return (string)$xml->version;
	}

	/** @return list<string> */
	private static function migrations(): array {
		$names = array_map(fn (string $f) => basename($f, '.php'), glob(__DIR__ . '/../../../lib/Migration/Version*.php') ?: []);
		sort($names);
		return $names;
	}

	public function testEveryNewMigrationComesWithAHigherVersion(): void {
		$current = self::appVersion();
		$migrations = self::migrations();
		foreach (self::SHIPPED as $version => $shipped) {
			$new = array_values(array_diff($migrations, $shipped));
			if ($new === []) {
				continue;
			}
			$this->assertTrue(version_compare($current, $version, '>'),
				"Neue Migration(en) " . implode(', ', $new) . " seit $version, aber info.xml steht auf $current – Nextcloud führte sie auf $version-Instanzen nie aus");
		}
	}

	public function testMigration1003IsNotPartOf080(): void {
		// Precondition of the test above: the block table was only added after fb4395c (0.8.0)
		$this->assertContains('Version1003Date20261004120000', self::migrations());
		$this->assertTrue(version_compare(self::appVersion(), '0.8.0', '>'));
	}

	public function testPackageJsonMatchesInfoXml(): void {
		$package = json_decode((string)file_get_contents(__DIR__ . '/../../../package.json'), true);
		$this->assertSame(self::appVersion(), $package['version'] ?? null);
	}
}
