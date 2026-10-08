<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\BackgroundJob\TagSyncJob;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * Tags a single file or folder immediately (after upload, move, copy),
 * so that new files don't stay untagged until the next daily run.
 *
 * The area comes from the mount (NodeRoots); for shares, the file is looked up in the
 * owner's view. External storage etc. is skipped.
 */
class NodeTagger {
	public function __construct(
		private FileCacheReader $fileCache,
		private RuleService $rules,
		private RetentionRunner $runner,
		private TagService $tags,
		private IJobList $jobList,
		private NodeRoots $nodeRoots,
	) {
	}

	/**
	 * @param bool $subtree for folders, all contents as well (via background job)
	 */
	public function tag(Node $node, bool $subtree): void {
		$node = $this->nodeRoots->ownerView($node);
		if ($node === null) {
			return;
		}
		$root = $this->nodeRoots->rootFor($node);
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
}
