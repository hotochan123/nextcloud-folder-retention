<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Dav;

use OCA\FolderRetention\Service\DeletionInfo;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * WebDAV property with the deletion date and rule (DeletionInfo) as JSON, for the badge and
 * the sidebar tab in the Files app.
 *
 * Only computed when a client asks for it – sync clients never do. For a folder listing
 * (depth 1) all children are computed together in preloadCollection, like the tags plugin
 * does. The DAV nodes come from the dav app (OCA\DAV\Connector\Sabre\Node); they are
 * recognised by getNode() so that this class does not depend on the dav app's internals.
 */
class DeletionInfoPlugin extends ServerPlugin {
	public const PROPERTY = '{http://nextcloud.org/ns}folder-retention';

	/** @var array<int, ?string> file ID → JSON, null = not managed */
	private array $cache = [];

	public function __construct(
		private DeletionInfo $info,
		private LoggerInterface $logger,
	) {
	}

	public function initialize(Server $server): void {
		$server->on('preloadCollection', $this->preloadCollection(...));
		$server->on('propFind', $this->propFind(...));
	}

	public function getPluginName(): string {
		return 'folder_retention';
	}

	private function preloadCollection(PropFind $propFind, ICollection $collection): void {
		if ($propFind->getStatus(self::PROPERTY) === null) {
			return;
		}
		$folder = self::fileNode($collection);
		if ($folder === null || array_key_exists((int)$folder->getId(), $this->cache)) {
			return;
		}
		$nodes = [$folder];
		foreach ($collection->getChildren() as $child) {
			$node = self::fileNode($child);
			if ($node !== null) {
				$nodes[] = $node;
			}
		}
		$this->compute($nodes);
	}

	private function propFind(PropFind $propFind, INode $node): void {
		$file = self::fileNode($node);
		if ($file === null) {
			return;
		}
		$propFind->handle(self::PROPERTY, function () use ($file): ?string {
			$id = (int)$file->getId();
			if (!array_key_exists($id, $this->cache)) {
				$this->compute([$file]);
			}
			return $this->cache[$id] ?? null;
		});
	}

	/** @param list<Node> $nodes */
	private function compute(array $nodes): void {
		try {
			foreach ($this->info->forNodes($nodes) as $id => $info) {
				$this->cache[$id] = $info === null ? null : json_encode($info, JSON_THROW_ON_ERROR);
			}
		} catch (\Throwable $e) {
			// display only – a listing must never fail because of it
			$this->logger->error('folder_retention: deletion date for the Files app could not be determined', ['exception' => $e]);
			foreach ($nodes as $node) {
				$this->cache[(int)$node->getId()] = null;
			}
		}
	}

	private static function fileNode(INode $node): ?Node {
		if (!method_exists($node, 'getNode')) {
			return null;
		}
		try {
			$file = $node->getNode();
		} catch (\Throwable) {
			return null;
		}
		return $file instanceof Node ? $file : null;
	}
}
