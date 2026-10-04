<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use OCA\FolderRetention\AppInfo\Application;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\RunStats;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Informational system tags ("Retention: 2 weeks") on files and folders.
 *
 * The tags only reflect which retention period currently applies – deletion is based solely
 * on the folder rules; tags are never evaluated.
 *
 * Mappings are deliberately written directly to oc_systemtag_object_mapping instead of via
 * ISystemTagObjectMapper: the core path fires events per file – the Activity app then writes
 * an entry for every user with access ("Admin assigned tag …"), and Flow rules on
 * "tag assigned" would trigger. Neither is wanted here.
 *
 * Tags are "restricted": visible, but assignable by admins only. Manual changes are
 * corrected by the next run. Only tags that this app created itself are managed (and
 * possibly removed) (registry in IAppConfig).
 */
class TagService {
	public const OBJECT_TYPE = 'files';
	private const REGISTRY = 'tag_registry';
	private const TAG_TABLE = 'systemtag';
	private const MAP_TABLE = 'systemtag_object_mapping';
	private const COLOR_DELETE = 'c25400';
	private const COLOR_KEEP = '2f63b8';

	/** @var array<string, int>|null tag name → ID */
	private ?array $registry = null;

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private ContentLanguage $language,
	) {
	}

	/**
	 * Tag name, always in the fixed instance language (ContentLanguage): system tags are
	 * instance-wide and matched by name. German: „Aufbewahrung: 2 Wochen“, „Aufbewahrung: unbegrenzt“.
	 */
	public function labelFor(Period $period): string {
		if (!$this->language->reliable()) {
			// a tag in another language would be a new tag – callers pause tag sync instead
			throw new \RuntimeException('folder_retention: content language unavailable, not creating tags');
		}
		$l = $this->language->l10n();
		return $l->t('Retention: %s', [$period->isNever() ? $l->t('unlimited') : $period->label($l)]);
	}

	/** Tag ID for a retention period; creates the tag if needed */
	public function tagIdFor(Period $period): int {
		$label = $this->labelFor($period);
		$registry = $this->registry();
		if (isset($registry[$label])) {
			return $registry[$label];
		}
		$id = $this->createTag($label, $period->isNever() ? self::COLOR_KEEP : self::COLOR_DELETE);
		$this->registry[$label] = $id;
		$this->saveRegistry();
		return $id;
	}

	/** @return list<int> IDs of all tags created by the app */
	public function managedTagIds(): array {
		return array_values(array_unique($this->registry()));
	}

	/**
	 * Reconciles the tags of the given objects.
	 *
	 * @param array<int, ?int> $desired fileid → desired tag ID (null = no retention tag)
	 */
	public function apply(array $desired, ?RunStats $stats = null): void {
		$managed = $this->managedTagIds();
		if ($desired === []) {
			return;
		}

		$existing = []; // fileid → list<tagId>
		if ($managed !== []) {
			foreach (array_chunk(array_map('strval', array_keys($desired)), 1000) as $chunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('objectid', 'systemtagid')->from(self::MAP_TABLE)
					->where($qb->expr()->eq('objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
					->andWhere($qb->expr()->in('objectid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
					->andWhere($qb->expr()->in('systemtagid', $qb->createNamedParameter($managed, IQueryBuilder::PARAM_INT_ARRAY)));
				$result = $qb->executeQuery();
				while ($row = $result->fetch()) {
					$existing[(int)$row['objectid']][] = (int)$row['systemtagid'];
				}
				$result->closeCursor();
			}
		}

		$remove = []; // tagId → list<objectid>
		$add = [];    // list<[objectid, tagId]>
		foreach ($desired as $fileId => $tagId) {
			$have = $existing[$fileId] ?? [];
			foreach ($have as $t) {
				if ($t !== $tagId) {
					$remove[$t][] = (string)$fileId;
				}
			}
			if ($tagId !== null && !in_array($tagId, $have, true)) {
				$add[] = [(string)$fileId, $tagId];
			}
		}
		if ($remove === [] && $add === []) {
			return;
		}

		$this->db->beginTransaction();
		try {
			foreach ($remove as $tagId => $objectIds) {
				foreach (array_chunk($objectIds, 1000) as $chunk) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete(self::MAP_TABLE)
						->where($qb->expr()->eq('objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
						->andWhere($qb->expr()->eq('systemtagid', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
						->andWhere($qb->expr()->in('objectid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
					$n = $qb->executeStatement();
					if ($stats !== null) {
						$stats->tagsRemoved += $n;
					}
				}
			}
			$insert = $this->db->getQueryBuilder();
			$insert->insert(self::MAP_TABLE)->values([
				'objectid' => $insert->createParameter('objectid'),
				'objecttype' => $insert->createNamedParameter(self::OBJECT_TYPE),
				'systemtagid' => $insert->createParameter('tagid'),
			]);
			foreach ($add as [$objectId, $tagId]) {
				$insert->setParameter('objectid', $objectId);
				$insert->setParameter('tagid', $tagId, IQueryBuilder::PARAM_INT);
				try {
					$insert->executeStatement();
					if ($stats !== null) {
						$stats->tagsAdded++;
					}
				} catch (DbException $e) {
					if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
						throw $e;
					}
				}
			}
			$this->touchTags(array_unique(array_merge(array_keys($remove), array_column($add, 1))));
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Removes all retention tags from all files (the tags themselves remain) */
	public function removeAll(): int {
		$managed = $this->managedTagIds();
		if ($managed === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::MAP_TABLE)
			->where($qb->expr()->eq('objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
			->andWhere($qb->expr()->in('systemtagid', $qb->createNamedParameter($managed, IQueryBuilder::PARAM_INT_ARRAY)));
		$n = $qb->executeStatement();
		$this->touchTags($managed);
		return $n;
	}

	/**
	 * Removes retention tags from objects that no run reaches anymore:
	 * deleted files and files in the trash bin or in versions.
	 */
	public function sweepOrphans(): int {
		$managed = $this->managedTagIds();
		if ($managed === []) {
			return 0;
		}
		$removed = 0;
		$after = '';
		while (true) {
			// objectid is varchar – match in PHP instead of via JOIN with a cast
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('objectid')->from(self::MAP_TABLE)
				->where($qb->expr()->eq('objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
				->andWhere($qb->expr()->in('systemtagid', $qb->createNamedParameter($managed, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->gt('objectid', $qb->createNamedParameter($after)))
				->orderBy('objectid', 'ASC')
				->setMaxResults(1000);
			$ids = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
			if ($ids === []) {
				return $removed;
			}
			$after = (string)end($ids);

			$numeric = array_map('intval', array_filter($ids, 'ctype_digit'));
			$alive = [];
			if ($numeric !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('fileid', 'path')->from('filecache')
					->where($qb->expr()->in('fileid', $qb->createNamedParameter($numeric, IQueryBuilder::PARAM_INT_ARRAY)));
				$result = $qb->executeQuery();
				while ($row = $result->fetch()) {
					$path = (string)$row['path'];
					if (!str_starts_with($path, 'files_trashbin/') && !str_starts_with($path, 'files_versions/')) {
						$alive[(string)$row['fileid']] = true;
					}
				}
				$result->closeCursor();
			}
			$dead = array_values(array_filter($ids, fn ($id) => !isset($alive[(string)$id])));
			if ($dead !== []) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete(self::MAP_TABLE)
					->where($qb->expr()->eq('objecttype', $qb->createNamedParameter(self::OBJECT_TYPE)))
					->andWhere($qb->expr()->in('systemtagid', $qb->createNamedParameter($managed, IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($qb->expr()->in('objectid', $qb->createNamedParameter(array_map('strval', $dead), IQueryBuilder::PARAM_STR_ARRAY)));
				$removed += $qb->executeStatement();
			}
		}
	}

	/** @return array<string, int> tag name → ID, only tags that still exist */
	private function registry(): array {
		if ($this->registry !== null) {
			return $this->registry;
		}
		$raw = json_decode($this->appConfig->getValueString(Application::APP_ID, self::REGISTRY, '{}'), true);
		$registry = is_array($raw) ? array_map('intval', $raw) : [];

		if ($registry !== []) {
			// forget tags deleted by admins – they are recreated when needed
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from(self::TAG_TABLE)
				->where($qb->expr()->in('id', $qb->createNamedParameter(array_values($registry), IQueryBuilder::PARAM_INT_ARRAY)));
			$present = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
			$pruned = array_filter($registry, fn (int $id) => in_array($id, $present, true));
			if (count($pruned) !== count($registry)) {
				$this->registry = $pruned;
				$this->saveRegistry();
			}
			$registry = $pruned;
		}
		return $this->registry = $registry;
	}

	private function saveRegistry(): void {
		$this->appConfig->setValueString(Application::APP_ID, self::REGISTRY, json_encode($this->registry, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Creates a restricted tag. If the name already exists as a foreign tag (e.g. an
	 * old files_retention tag), it is NOT adopted; instead a name of our own is chosen –
	 * otherwise our mappings would trigger foreign rules.
	 */
	private function createTag(string $label, string $color): int {
		$l = $this->language->l10n();
		foreach ([$label, $l->t('%s (folder rule)', [$label]), $l->t('%s (folder rule 2)', [$label])] as $name) {
			if ($this->nameTaken($name)) {
				$this->logger->warning('folder_retention: tag name "' . $name . '" is already taken, using an alternative');
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->insert(self::TAG_TABLE)->values([
				'name' => $qb->createNamedParameter(mb_substr($name, 0, 64)),
				'visibility' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
				'editable' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'etag' => $qb->createNamedParameter(md5(uniqid('', true))),
				'color' => $qb->createNamedParameter($color),
			]);
			try {
				$qb->executeStatement();
				return $qb->getLastInsertId();
			} catch (DbException $e) {
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				// created concurrently by another process – check again
				$id = $this->findTag($name);
				if ($id !== null) {
					return $id;
				}
			}
		}
		throw new \RuntimeException('No free tag name for "' . $label . '"');
	}

	private function nameTaken(string $name): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TAG_TABLE)
			->where($qb->expr()->eq($qb->func()->lower('name'), $qb->createNamedParameter(mb_strtolower($name))))
			->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	private function findTag(string $name): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from(self::TAG_TABLE)
			->where($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('editable', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$id = $qb->executeQuery()->fetchOne();
		return $id === false ? null : (int)$id;
	}

	/** Renew the tags' ETag so clients reload their tag lists (like the core mapper) */
	private function touchTags(array $tagIds): void {
		if ($tagIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TAG_TABLE)
			->set('etag', $qb->createNamedParameter(md5(uniqid('', true))))
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values($tagIds), IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}
}
