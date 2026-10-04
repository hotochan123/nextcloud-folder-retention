<?php

declare(strict_types=1);

namespace OC\Files\ObjectStore;

use OCP\Files\ObjectStore\IObjectStore;

/**
 * Nur für den Harness (dev/it, S23): Objektspeicher als Primärspeicher ohne S3 – jedes Objekt
 * ist eine Datei in einem Verzeichnis. Nextcloud verwaltet die Konten dann als
 * HomeObjectStoreStorage mit normalem Cache (nicht HomeCache) – wie bei S3/Swift.
 * Liegt unter lib/private/Files/ObjectStore, damit der Autoloader (PSR-4 „OC\“) sie findet.
 */
class FretDirObjectStore implements IObjectStore {
	private string $dir;

	public function __construct(array $arguments) {
		$this->dir = rtrim((string)($arguments['dir'] ?? '/var/www/html/data/objects'), '/');
		if (!is_dir($this->dir)) {
			mkdir($this->dir, 0770, true);
		}
	}

	private function file(string $urn): string {
		return $this->dir . '/' . str_replace(['/', '\\'], '_', $urn);
	}

	public function getStorageId() {
		return 'fretdir::' . $this->dir;
	}

	public function readObject($urn) {
		$h = @fopen($this->file($urn), 'rb');
		if ($h === false) {
			throw new \Exception('Objekt fehlt: ' . $urn);
		}
		return $h;
	}

	public function writeObject($urn, $stream, ?string $mimetype = null) {
		$h = fopen($this->file($urn), 'wb');
		if ($h === false) {
			throw new \Exception('Objekt nicht schreibbar: ' . $urn);
		}
		stream_copy_to_stream($stream, $h);
		fclose($h);
	}

	public function deleteObject($urn) {
		@unlink($this->file($urn));
	}

	public function objectExists($urn) {
		return is_file($this->file($urn));
	}

	public function copyObject($from, $to) {
		if (!copy($this->file($from), $this->file($to))) {
			throw new \Exception('Kopieren fehlgeschlagen: ' . $from);
		}
	}

	public function preSignedUrl(string $urn, \DateTimeInterface $expiration): ?string {
		return null;
	}
}
