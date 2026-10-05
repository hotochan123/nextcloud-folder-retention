<?php

declare(strict_types=1);

namespace OC\Files\ObjectStore;

use OCP\Files\ObjectStore\IObjectStore;

/**
 * Harness only (dev/it, S23): object store as primary storage without S3 – every object
 * is a file in a directory. Nextcloud then manages the accounts as
 * HomeObjectStoreStorage with a normal cache (not HomeCache) – as with S3/Swift.
 * Lives under lib/private/Files/ObjectStore so the autoloader (PSR-4 "OC\") finds it.
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
			throw new \Exception('object missing: ' . $urn);
		}
		return $h;
	}

	public function writeObject($urn, $stream, ?string $mimetype = null) {
		$h = fopen($this->file($urn), 'wb');
		if ($h === false) {
			throw new \Exception('object not writable: ' . $urn);
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
			throw new \Exception('copy failed: ' . $from);
		}
	}

	public function preSignedUrl(string $urn, \DateTimeInterface $expiration): ?string {
		return null;
	}
}
