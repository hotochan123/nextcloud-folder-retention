<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\BackgroundJob\TagSyncJob;
use OCA\FolderRetention\Model\RetentionRoot;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\IHomeMountProvider;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;

/**
 * Tags a single file or folder immediately (after upload, move, copy),
 * so that new files don't stay untagged until the next daily run.
 *
 * The area is read directly from the mount instead of via RootProvider::getRoots() –
 * that would be far too expensive per upload. For shares, the file is looked up in the
 * owner's view (it lives in the owner's home storage). External storage etc.
 * is skipped.
 */
class NodeTagger {
	public function __construct(
		private FileCacheReader $fileCache,
		private RuleService $rules,
		private RetentionRunner $runner,
		private TagService $tags,
		private IJobList $jobList,
		private IRootFolder $rootFolder,
		private Settings $settings,
	) {
	}

	/**
	 * @param bool $subtree for folders, all contents as well (via background job)
	 */
	public function tag(Node $node, bool $subtree): void {
		$node = $this->ownerView($node);
		if ($node === null) {
			return;
		}
		$root = $this->rootFor($node);
		if ($root === null) {
			return;
		}
		$fileId = (int)$node->getId();
		$isFolder = $node instanceof Folder;
		$entry = $this->fileCache->getEntry($fileId);
		if ($entry === null) {
			return;
		}

		$this->fileCache->reset();
		$chain = $this->fileCache->chain($isFolder ? $fileId : $entry['parent'], $root->rootId);
		if ($chain === null) {
			return; // outside files/ or the team folder
		}
		$this->tags->apply([$fileId => $this->runner->desiredTag($root, $chain, $this->rules->snapshot())]);

		if ($isFolder && $subtree) {
			$this->jobList->add(TagSyncJob::class, ['folderId' => $fileId]);
		}
	}

	/** For shares: the same node in the owner's view (also for reshared shares) */
	private function ownerView(Node $node): ?Node {
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

	private function rootFor(Node $node): ?RetentionRoot {
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
