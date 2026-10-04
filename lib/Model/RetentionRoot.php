<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

/**
 * An area the job evaluates: a team folder or the files/ folder of a home storage.
 * Shares are not areas of their own – their files live in the owner's area.
 */
final class RetentionRoot {
	public const KIND_TEAM = 'team';
	public const KIND_HOME = 'home';
	/** Home storage of an account marked as a workspace account (functional account with shared folders) */
	public const KIND_WORKSPACE = 'workspace';

	/**
	 * @param list<string> $userIds users through whose view deletion is possible
	 *                              (home: the owner; team folder: members)
	 */
	public function __construct(
		public readonly string $kind,
		public readonly int $storageId,
		public readonly int $rootId,
		/** internal path of the root folder in the storage, e.g. "files" or "__groupfolders/3" */
		public readonly string $rootPath,
		/** display name, e.g. "SVZ Workspace - Academy" or "Personal · alice" */
		public readonly string $label,
		public readonly array $userIds,
	) {
	}

	/** Stable key for sorting and the job cursor */
	public function key(): string {
		return sprintf('%s:%010d:%012d', $this->kind, $this->storageId, $this->rootId);
	}

	/**
	 * Key for the safety lock: without the kind – if an admin switches an account between
	 * personal and workspace, the same storage stays locked.
	 */
	public function blockKey(): string {
		return sprintf('%010d:%012d', $this->storageId, $this->rootId);
	}

	/** Personal area – the default rule only applies here if the setting is enabled */
	public function isHome(): bool {
		return $this->kind === self::KIND_HOME;
	}

	/** Home storage of an account (personal or workspace); owner = userIds[0] */
	public function isAccount(): bool {
		return $this->kind === self::KIND_HOME || $this->kind === self::KIND_WORKSPACE;
	}

	/** Is the internal path inside this area (including the root itself)? */
	public function containsPath(string $path): bool {
		return $this->rootPath === '' || $path === $this->rootPath || str_starts_with($path, $this->rootPath . '/');
	}

	/** Display path for log/UI: "<Label>/<relative path>" */
	public function displayPath(string $internalPath): string {
		$rel = $this->rootPath === '' ? $internalPath : ltrim(substr($internalPath, strlen($this->rootPath)), '/');
		return $rel === '' ? $this->label : $this->label . '/' . $rel;
	}
}
