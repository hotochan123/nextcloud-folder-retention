<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Model\Decision;
use OCA\FolderRetention\Model\FileRow;
use OCA\FolderRetention\Model\Resolution;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Model\RuleSet;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * Deletion date and rule of files and folders for the Files app (badge + sidebar tab).
 *
 * Computed like the preview, from the rules and the file cache – nothing is stored, nothing
 * recorded. The date is a forecast: rule changes, simulation mode, the deletion limit or a
 * lock postpone the actual deletion; the flags in the result say which of them applies now.
 *
 * Result per node (null = not in a managed area):
 *   type        'file' | 'folder' (folder: the rule for files directly inside)
 *   expiresAt   files: deletion date (unix time), null = never / no reference date
 *   skip        null | 'never' | 'no_date' | 'personal_default' (no rule applies)
 *   period      {value, unit} of the rule
 *   basis       'created' | 'modified'
 *   referenceDate, referenceSource  files only
 *   source      {kind: 'default'|'personal'|'area'|'folder'|'own', name: ?string}; 'own' = the
 *               queried folder itself carries the rule
 *               name stays empty for nodes reached through a share – the owner's folder
 *               names are none of the recipient's business
 *   simulation, halted, blocked, noCron  why nothing is deleted at the moment
 */
class DeletionInfo {
	private ?RuleSet $ruleSet = null;
	/** @var array<int, array{0: ?RetentionRoot, 1: bool}> mount → [area, reached through a share] */
	private array $byMount = [];

	public function __construct(
		private NodeRoots $nodeRoots,
		private FileCacheReader $fileCache,
		private RuleService $rules,
		private RuleResolver $resolver,
		private Evaluator $evaluator,
		private RetentionRunner $runner,
		private Settings $settings,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param list<Node> $nodes nodes as the requesting user sees them
	 * @return array<int, array<string, mixed>|null> file ID (as seen by the user) → info
	 */
	public function forNodes(array $nodes): array {
		$this->ruleSet ??= $this->rules->snapshot();
		$now = $this->time->getTime();
		$tz = $this->settings->timezone();

		/** @var array<int, array{0: RetentionRoot, 1: bool, 2: bool}> $located id → [root, viaShare, isFolder] */
		$located = [];
		$result = [];
		foreach ($nodes as $node) {
			$id = (int)$node->getId();
			$result[$id] = null;
			[$root, $viaShare] = $this->area($node);
			if ($root !== null) {
				$located[$id] = [$root, $viaShare, $node instanceof Folder];
			}
		}
		if ($located === []) {
			return $result;
		}

		$rows = $this->fileCache->getFileRows(array_keys($located));
		$files = array_values(array_filter($rows, fn (FileRow $r) => !$located[$r->fileId][2]));
		foreach ($this->runner->withHistory($files) as $file) {
			$rows[$file->fileId] = $file;
		}
		$this->fileCache->prefetch(array_map(fn (FileRow $r) => $located[$r->fileId][2] ? $r->fileId : $r->parentId, array_values($rows)));

		$flags = [
			'simulation' => $this->settings->isSimulation(),
			'halted' => $this->settings->deletionHalt() !== null,
			'noCron' => $this->settings->backgroundJobsMode() !== 'cron',
		];
		$sourceIds = [];
		foreach ($rows as $id => $row) {
			[$root, $viaShare, $isFolder] = $located[$id];
			$ruleSet = $this->ruleSet->forRoot($root);
			$chain = $this->fileCache->chain($isFolder ? $id : $row->parentId, $root->rootId);
			if ($chain === null) {
				continue; // outside files/ or the team folder
			}
			$defaultApplies = !$root->isHome() || $ruleSet->personal !== null;
			if ($isFolder) {
				$resolution = $this->resolver->resolve($chain, $ruleSet->byFolderId, $ruleSet->default);
				$skip = $resolution->isDefault() && !$defaultApplies ? Decision::SKIP_PERSONAL
					: ($resolution->rule->period->isNever() ? Decision::SKIP_NEVER : null);
				$info = ['type' => 'folder', 'expiresAt' => null];
			} else {
				$decision = $this->evaluator->evaluate($row, $chain, $ruleSet, $defaultApplies, $tz, $now);
				$resolution = $decision->resolution;
				$skip = $decision->skipReason;
				$info = [
					'type' => 'file',
					'expiresAt' => $decision->expiresAt,
					'referenceDate' => $decision->reference?->timestamp,
					'referenceSource' => $decision->reference?->source,
				];
			}
			$rule = $resolution->rule;
			$source = $this->source($resolution, $root, $isFolder ? $id : null);
			if ($viaShare) {
				$source['name'] = null;
			} elseif ($source['kind'] === 'folder') {
				$sourceIds[$id] = $resolution->sourceFolderId();
			}
			$result[$id] = $info + [
				'skip' => $skip,
				'period' => ['value' => $rule->period->value, 'unit' => $rule->period->unit->value],
				'basis' => $rule->basis->value,
				'source' => $source,
				'blocked' => $this->settings->isRootBlocked($root->blockKey()),
			] + $flags;
		}

		$names = $this->fileCache->getNames(array_values(array_unique($sourceIds)));
		foreach ($sourceIds as $id => $folderId) {
			$result[$id]['source']['name'] = $names[$folderId] ?? null;
		}
		return $result;
	}

	/**
	 * Area of a node, once per mount: all entries of a folder listing share it, and looking up
	 * the owner's view of a shared file costs a query per file.
	 *
	 * @return array{0: ?RetentionRoot, 1: bool} area (null = not managed), reached through a share
	 */
	private function area(Node $node): array {
		$key = spl_object_id($node->getMountPoint());
		if (!isset($this->byMount[$key])) {
			$owner = $this->nodeRoots->ownerView($node);
			$this->byMount[$key] = [$owner === null ? null : $this->nodeRoots->rootFor($owner), $owner !== null && $owner !== $node];
		}
		return $this->byMount[$key];
	}

	/** @return array{kind: string, name: ?string} */
	private function source(Resolution $resolution, RetentionRoot $root, ?int $folderId): array {
		if ($resolution->isDefault()) {
			return ['kind' => $resolution->rule->personal ? 'personal' : 'default', 'name' => null];
		}
		if ($resolution->sourceFolderId() === $root->rootId) {
			return ['kind' => 'area', 'name' => null];
		}
		return ['kind' => $resolution->sourceFolderId() === $folderId ? 'own' : 'folder', 'name' => null];
	}
}
