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
 * Taggt eine einzelne Datei bzw. einen Ordner sofort (nach Hochladen, Verschieben, Kopieren),
 * damit neue Dateien nicht bis zum nächsten Tageslauf ohne Tag bleiben.
 *
 * Der Bereich wird direkt am Mount abgelesen statt über RootProvider::getRoots() –
 * das wäre pro Upload viel zu teuer. Bei Freigaben wird die Datei in der Sicht des
 * Besitzers nachgeschlagen (sie liegt in dessen Home-Storage). Externe Speicher usw.
 * werden übergangen.
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
	 * @param bool $subtree bei Ordnern auch alle Inhalte (per Hintergrundjob)
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
			return; // außerhalb von files/ bzw. des Team-Ordners
		}
		$this->tags->apply([$fileId => $this->runner->desiredTag($root, $chain, $this->rules->snapshot())]);

		if ($isFolder && $subtree) {
			$this->jobList->add(TagSyncJob::class, ['folderId' => $fileId]);
		}
	}

	/** Bei Freigaben: derselbe Knoten in der Sicht des Besitzers (auch bei weitergeteilten Freigaben) */
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
