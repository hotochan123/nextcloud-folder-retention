<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\Db\Rule;
use OCA\FolderRetention\Model\Resolution;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Model\RuleSet;
use OCP\IL10N;

/**
 * Ordnerbaum für die Admin-Oberfläche: nur Ordner, lazy, jeweils mit effektiver Regel und Quelle.
 */
class TreeService {
	public function __construct(
		private RootProvider $roots,
		private FileCacheReader $fileCache,
		private RuleResolver $resolver,
		private RuleService $rules,
		private Settings $settings,
		private IL10N $l,
	) {
	}

	/** @return list<array<string, mixed>> Bereichswurzeln (Team-Ordner und Arbeitsbereiche zuerst, dann persönliche) */
	public function roots(): array {
		$ruleSet = $this->rules->snapshot();
		$ruleEntities = $this->ruleEntities();
		$nodes = [];
		foreach ($this->roots->getRoots() as $root) {
			$children = $this->fileCache->childFolders($root->rootId);
			$nodes[] = $this->node($root, $root->rootId, $root->label, $root->rootPath, null, count($children), [$root->rootId], $ruleSet, $ruleEntities);
		}
		usort($nodes, fn ($a, $b) => [$a['kind'] === RetentionRoot::KIND_HOME, mb_strtolower($a['name'])] <=> [$b['kind'] === RetentionRoot::KIND_HOME, mb_strtolower($b['name'])]);
		return $nodes;
	}

	/** @return list<array<string, mixed>>|null null = Ordner unbekannt/außerhalb der Bereiche */
	public function children(int $parentId): ?array {
		$located = $this->roots->locate($parentId);
		if ($located === null) {
			return null;
		}
		[$root, $parentChain] = $located;
		$ruleSet = $this->rules->snapshot();
		$ruleEntities = $this->ruleEntities();

		$nodes = [];
		foreach ($this->fileCache->childFolders($parentId) as $child) {
			$nodes[] = $this->node($root, $child['fileid'], $child['name'], $child['path'], $parentId, $child['childFolders'],
				array_merge([$child['fileid']], $parentChain), $ruleSet, $ruleEntities);
		}
		return $nodes;
	}

	/** Anzeigepfad eines Ordners, z. B. „SVZ Workspace - Academy/Archiv“; null = verwaist */
	public function displayPath(int $folderId): ?string {
		$located = $this->roots->locate($folderId);
		$entry = $this->fileCache->getEntry($folderId);
		return ($located === null || $entry === null) ? null : $located[0]->displayPath($entry['path']);
	}

	/**
	 * Kompletter Ordner-Teilbaum als flache Liste (für „Alle aufklappen“ und die clientseitige
	 * Live-Auflösung ungespeicherter Änderungen).
	 *
	 * @return array{nodes: list<array<string, mixed>>, truncated: bool}|null
	 */
	public function descendants(int $folderId, int $limit = 20000): ?array {
		$located = $this->roots->locate($folderId);
		$entry = $this->fileCache->getEntry($folderId);
		if ($located === null || $entry === null) {
			return null;
		}
		[$root] = $located;
		$rows = $this->fileCache->descendantFolders($root->storageId, $entry['path'], $limit + 1);
		$truncated = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);

		$childCount = [];
		foreach ($rows as $r) {
			$childCount[$r['parent']] = ($childCount[$r['parent']] ?? 0) + 1;
		}
		$nodes = array_map(fn ($r) => [
			'id' => $r['fileid'],
			'parentId' => $r['parent'],
			'name' => $r['name'],
			'path' => $root->displayPath($r['path']),
			'kind' => $root->kind,
			'isRoot' => false,
			'childCount' => $childCount[$r['fileid']] ?? 0,
			'hasChildren' => ($childCount[$r['fileid']] ?? 0) > 0,
		], $rows);
		usort($nodes, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
		return ['nodes' => $nodes, 'truncated' => $truncated];
	}

	/**
	 * Wirkung einer Ordnerregel auf den Teilbaum: wie viele Unterordner übernehmen sie,
	 * welche Unterordner haben abweichende eigene Regeln.
	 *
	 * @return array<string, mixed>|null
	 */
	public function impact(int $folderId): ?array {
		$located = $this->roots->locate($folderId);
		$entry = $this->fileCache->getEntry($folderId);
		if ($located === null || $entry === null) {
			return null;
		}
		[$root, $chain] = $located;
		$ruleSet = $this->rules->snapshot();
		$rootRules = $ruleSet->forRoot($root);

		$descendants = $this->fileCache->descendantFolders($root->storageId, $entry['path']);
		$own = $ruleSet->byFolderId[$folderId] ?? null;
		$inheriting = 0;
		$overrides = [];
		$names = [];
		foreach ($descendants as $d) {
			$dChain = $this->fileCache->chain($d['fileid'], $root->rootId);
			if ($dChain === null) {
				continue;
			}
			$res = $this->resolver->resolve($dChain, $rootRules->byFolderId, $rootRules->default);
			if ($own !== null && $res->rule === $own) {
				$inheriting++;
			}
			if (isset($ruleSet->byFolderId[$d['fileid']])) {
				$rule = $ruleSet->byFolderId[$d['fileid']];
				$overrides[] = [
					'folderId' => $d['fileid'],
					'name' => $d['name'],
					'path' => $root->displayPath($d['path']),
					'label' => $rule->period->label($this->l),
					'never' => $rule->period->isNever(),
					'scope' => $rule->scope?->value,
				];
			}
		}
		usort($overrides, fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));

		return [
			'folderId' => $folderId,
			'descendantCount' => count($descendants),
			'inheritingCount' => $inheriting,
			'overrides' => $overrides,
			'childrenInherit' => $this->describe($this->resolver->resolveForChildren($chain, $rootRules->byFolderId, $rootRules->default), $root, $chain),
		];
	}

	/**
	 * @param list<int> $chain [dieser Ordner, Eltern, …, Bereichswurzel]
	 * @param array<int, Rule> $ruleEntities
	 */
	private function node(RetentionRoot $root, int $id, string $name, string $path, ?int $parentId, int $childFolders, array $chain, RuleSet $ruleSet, array $ruleEntities): array {
		return [
			'id' => $id,
			'parentId' => $parentId,
			'name' => $name,
			'path' => $root->displayPath($path),
			'kind' => $root->kind,
			'isRoot' => $id === $root->rootId,
			// Konto hinter einem persönlichen bzw. Arbeitsbereich (nur an der Wurzel, für den Umschalter)
			'accountId' => $id === $root->rootId && $root->isAccount() ? ($root->userIds[0] ?? null) : null,
			'hasChildren' => $childFolders > 0,
			'childCount' => $childFolders,
			'chain' => $chain,
			'rule' => isset($ruleEntities[$id]) ? $ruleEntities[$id]->toArray($this->l) : null,
			'effective' => $this->describe($this->resolver->resolve($chain, $ruleSet->forRoot($root)->byFolderId, $ruleSet->forRoot($root)->default), $root, $chain),
		];
	}

	/** @param list<int> $chain */
	public function describe(Resolution $res, RetentionRoot $root, array $chain): array {
		$rule = $res->rule;
		$sourceId = $res->sourceFolderId();
		$sourceName = null;
		if ($sourceId !== null) {
			$sourceName = $sourceId === $root->rootId
				? $root->label
				: ($this->fileCache->getNames([$sourceId])[$sourceId] ?? ('#' . $sourceId));
		}
		return [
			'ruleId' => $rule->id,
			'label' => $rule->period->label($this->l),
			'periodUnit' => $rule->period->unit->value,
			'periodValue' => $rule->period->value,
			'never' => $rule->period->isNever(),
			'basis' => $rule->basis->value,
			'scope' => $rule->scope?->value,
			'notify' => $rule->notify,
			'isOwn' => $res->isOwn(),
			'isDefault' => $res->isDefault(),
			'isPersonalDefault' => $rule->personal,
			'sourceFolderId' => $sourceId,
			'sourceName' => $sourceName,
		];
	}

	/** @return array<int, Rule> */
	private function ruleEntities(): array {
		$out = [];
		foreach ($this->rules->list() as $rule) {
			if ($rule->getFolderId() !== null) {
				$out[(int)$rule->getFolderId()] = $rule;
			}
		}
		return $out;
	}
}
