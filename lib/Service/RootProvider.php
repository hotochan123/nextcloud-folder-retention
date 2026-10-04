<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Model\RetentionRoot;
use OCP\Files\Config\IHomeMountProvider;
use OCP\Files\Config\IUserMountCache;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Ermittelt die zu bewertenden Bereiche aus dem Mount-Cache (oc_mounts):
 *  - Team-Ordner (groupfolders) – jeweils einmal, egal wie viele Mitglieder
 *  - Home-Storages – nur der files/-Teil (nicht Papierkorb, Versionen, Cache);
 *    als Arbeitsbereich markierte Konten erscheinen mit ihrem Anzeigenamen
 * Freigaben (files_sharing), externe Speicher usw. werden übersprungen.
 *
 * Grenze: Der Mount-Cache kennt nur Mounts von Benutzern, die sich seit Anlage des
 * Team-Ordners mindestens einmal angemeldet haben bzw. deren Dateisystem eingerichtet wurde.
 */
class RootProvider {
	public const GROUPFOLDER_PROVIDER = 'OCA\\GroupFolders\\Mount\\MountProvider';
	/** Maximal so viele Benutzer je Team-Ordner merken (Löschversuche über deren Sicht) */
	private const MAX_USERS_PER_ROOT = 5;

	/** @var list<RetentionRoot>|null */
	private ?array $cache = null;

	public function __construct(
		private IUserManager $userManager,
		private IUserMountCache $mountCache,
		private FileCacheReader $fileCache,
		private Settings $settings,
		private ContentLanguage $language,
	) {
	}

	/** @return list<RetentionRoot> sortiert nach key() */
	public function getRoots(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}

		/** @var array<string, array{kind: string, storage: int, root: int, path: string, label: string, users: list<string>}> $found */
		$found = [];
		$workspaces = $this->settings->workspaceAccounts();
		$this->userManager->callForSeenUsers(function (IUser $user) use (&$found, $workspaces) {
			foreach ($this->mountCache->getMountsForUser($user) as $mount) {
				$provider = $mount->getMountProvider();
				if ($provider === self::GROUPFOLDER_PROVIDER) {
					$key = 'team:' . $mount->getStorageId() . ':' . $mount->getRootId();
					if (!isset($found[$key])) {
						$found[$key] = [
							'kind' => RetentionRoot::KIND_TEAM,
							'storage' => $mount->getStorageId(),
							'root' => $mount->getRootId(),
							'path' => $mount->getRootInternalPath(),
							'label' => basename(rtrim($mount->getMountPoint(), '/')),
							'users' => [],
						];
					}
					if (count($found[$key]['users']) < self::MAX_USERS_PER_ROOT) {
						$found[$key]['users'][] = $user->getUID();
					}
				} elseif ($provider !== '' && is_a($provider, IHomeMountProvider::class, true)) {
					$filesId = $this->fileCache->getIdByPath($mount->getStorageId(), 'files');
					if ($filesId === null) {
						continue;
					}
					$key = 'home:' . $mount->getStorageId();
					$isWorkspace = in_array($user->getUID(), $workspaces, true);
					$found[$key] = [
						'kind' => $isWorkspace ? RetentionRoot::KIND_WORKSPACE : RetentionRoot::KIND_HOME,
						'storage' => $mount->getStorageId(),
						'root' => $filesId,
						'path' => 'files',
						'label' => $isWorkspace ? ($user->getDisplayName() ?: $user->getUID()) : $this->language->l10n()->t('Personal · %s', [$user->getUID()]),
						'users' => [$user->getUID()],
					];
				}
			}
			return null;
		});

		$roots = array_map(fn (array $r) => new RetentionRoot($r['kind'], $r['storage'], $r['root'], $r['path'], $r['label'], $r['users']), array_values($found));
		usort($roots, fn (RetentionRoot $a, RetentionRoot $b) => strcmp($a->key(), $b->key()));
		return $this->cache = $roots;
	}

	/**
	 * Bereich, in dem ein Ordner/eine Datei liegt, samt Ordnerkette bis zur Bereichswurzel.
	 *
	 * @return array{0: RetentionRoot, 1: list<int>}|null
	 */
	public function locate(int $folderId): ?array {
		$entry = $this->fileCache->getEntry($folderId);
		if ($entry === null) {
			return null;
		}
		foreach ($this->getRoots() as $root) {
			if ($root->storageId !== $entry['storage'] || !$root->containsPath($entry['path'])) {
				continue;
			}
			$chain = $this->fileCache->chain($folderId, $root->rootId);
			if ($chain !== null) {
				return [$root, $chain];
			}
		}
		return null;
	}

	/** Existiert der Ordner, ist er wirklich ein Ordner und liegt er in einem verwalteten Bereich? */
	public function isManagedFolder(int $folderId): bool {
		$entry = $this->fileCache->getEntry($folderId);
		return $entry !== null && $entry['isFolder'] && $this->locate($folderId) !== null;
	}

	public function reset(): void {
		$this->cache = null;
	}
}
