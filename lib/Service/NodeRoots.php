<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Model\RetentionRoot;
use OCP\Files\Config\IHomeMountProvider;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;

/**
 * Area of a single node, read directly from its mount – RootProvider::getRoots() walks all
 * accounts and would be far too expensive per upload or folder listing. For shares, the node is
 * looked up in the owner's view (it lives in the owner's home storage). External storage etc.
 * is not an area.
 */
class NodeRoots {
	public function __construct(
		private FileCacheReader $fileCache,
		private IRootFolder $rootFolder,
		private Settings $settings,
	) {
	}

	/** For shares: the same node in the owner's view (also for reshared shares) */
	public function ownerView(Node $node): ?Node {
		for ($i = 0; $i < 5; $i++) {
			$storage = $node->getStorage();
			if (!$storage->instanceOfStorage(ISharedStorage::class)) {
				return $node;
			}
			/** @var ISharedStorage $storage */
			$owner = $storage->getShare()->getShareOwner();
			$node = $this->rootFolder->getUserFolder($owner)->getFirstNodeById((int)$node->getId());
			if ($node === null) {
				return null;
			}
		}
		return null;
	}

	/** Area of a node in its owner's view (see ownerView()); null = not managed */
	public function rootFor(Node $node): ?RetentionRoot {
		$mount = $node->getMountPoint();
		$provider = $mount->getMountProvider();
		$storageId = (int)$mount->getNumericStorageId();

		if ($provider === RootProvider::GROUPFOLDER_PROVIDER) {
			return new RetentionRoot(RetentionRoot::KIND_TEAM, $storageId, (int)$mount->getStorageRootId(), '', '', []);
		}
		if ($provider !== '' && is_a($provider, IHomeMountProvider::class, true)) {
			$filesId = $this->fileCache->getIdByPath($storageId, 'files');
			$owner = $node->getOwner()?->getUID() ?? '';
			$kind = $this->settings->isWorkspaceAccount($owner) ? RetentionRoot::KIND_WORKSPACE : RetentionRoot::KIND_HOME;
			return $filesId === null ? null : new RetentionRoot($kind, $storageId, $filesId, 'files', '', [$owner]);
		}
		return null;
	}
}
