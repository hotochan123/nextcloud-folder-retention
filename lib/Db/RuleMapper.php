<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Rule>
 */
class RuleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'folder_retention_rules', Rule::class);
	}

	/** @return list<Rule> */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id');
		return $this->findEntities($qb);
	}

	/** @param bool $personal true = Standardregel für persönliche Ordner */
	public function findDefault(bool $personal = false): ?Rule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->isNull('folder_id'))
			->andWhere($personal
				? $qb->expr()->eq('target', $qb->createNamedParameter(Rule::TARGET_PERSONAL))
				: $qb->expr()->isNull('target'))
			->orderBy('id')
			->setMaxResults(1);
		$rules = $this->findEntities($qb);
		return $rules[0] ?? null;
	}

	public function findByFolderId(int $folderId): ?Rule {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
