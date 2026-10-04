<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * Ein Bereich, den der Job bewertet: ein Team-Ordner oder der files/-Ordner eines Home-Storages.
 * Freigaben sind keine eigenen Bereiche – ihre Dateien liegen im Bereich des Besitzers.
 */
final class RetentionRoot {
	public const KIND_TEAM = 'team';
	public const KIND_HOME = 'home';
	/** Home-Storage eines als Arbeitsbereich markierten Kontos (Funktionskonto mit geteilten Ordnern) */
	public const KIND_WORKSPACE = 'workspace';

	/**
	 * @param list<string> $userIds Benutzer, über deren Sicht gelöscht werden kann
	 *                              (Home: der Besitzer; Team-Ordner: Mitglieder)
	 */
	public function __construct(
		public readonly string $kind,
		public readonly int $storageId,
		public readonly int $rootId,
		/** interner Pfad des Wurzelordners im Storage, z. B. "files" oder "__groupfolders/3" */
		public readonly string $rootPath,
		/** Anzeigename, z. B. "SVZ Workspace - Academy" oder "Persönlich · alice" */
		public readonly string $label,
		public readonly array $userIds,
	) {
	}

	/** Stabiler Schlüssel für Sortierung und Job-Cursor */
	public function key(): string {
		return sprintf('%s:%010d:%012d', $this->kind, $this->storageId, $this->rootId);
	}

	/**
	 * Schlüssel für die Sicherheitssperre: ohne Art – schaltet ein Admin ein Konto zwischen
	 * persönlich und Arbeitsbereich um, bleibt derselbe Speicher gesperrt.
	 */
	public function blockKey(): string {
		return sprintf('%010d:%012d', $this->storageId, $this->rootId);
	}

	/** Persönlicher Bereich – die Standardregel gilt hier nur mit Einstellung */
	public function isHome(): bool {
		return $this->kind === self::KIND_HOME;
	}

	/** Home-Storage eines Kontos (persönlich oder Arbeitsbereich); Besitzer = userIds[0] */
	public function isAccount(): bool {
		return $this->kind === self::KIND_HOME || $this->kind === self::KIND_WORKSPACE;
	}

	/** Liegt der interne Pfad innerhalb dieses Bereichs (die Wurzel selbst eingeschlossen)? */
	public function containsPath(string $path): bool {
		return $this->rootPath === '' || $path === $this->rootPath || str_starts_with($path, $this->rootPath . '/');
	}

	/** Anzeigepfad für Log/UI: "<Label>/<relativer Pfad>" */
	public function displayPath(string $internalPath): string {
		$rel = $this->rootPath === '' ? $internalPath : ltrim(substr($internalPath, strlen($this->rootPath)), '/');
		return $rel === '' ? $this->label : $this->label . '/' . $rel;
	}
}
