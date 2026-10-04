<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Db;

use JsonSerializable;
use OCA\FolderRetention\Model\Basis;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Model\RetentionRule;
use OCA\FolderRetention\Model\Scope;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use OCP\IL10N;

/**
 * @method ?int getFolderId()
 * @method void setFolderId(?int $folderId)
 * @method ?int getPeriodValue()
 * @method void setPeriodValue(?int $value)
 * @method string getPeriodUnit()
 * @method void setPeriodUnit(string $unit)
 * @method ?string getScope()
 * @method void setScope(?string $scope)
 * @method string getBasis()
 * @method void setBasis(string $basis)
 * @method ?bool getNotify()
 * @method void setNotify(bool $notify)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $uid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 * @method ?string getTarget()
 * @method void setTarget(?string $target)
 */
class Rule extends Entity implements JsonSerializable {
	/** Value of "target" for the default rule of personal folders */
	public const TARGET_PERSONAL = 'personal';

	protected $folderId;
	protected $target;
	protected $periodValue;
	protected $periodUnit;
	protected $scope;
	protected $basis;
	protected $notify;
	protected $createdBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('folderId', Types::BIGINT);
		$this->addType('target', Types::STRING);
		$this->addType('periodValue', Types::INTEGER);
		$this->addType('periodUnit', Types::STRING);
		$this->addType('scope', Types::STRING);
		$this->addType('basis', Types::STRING);
		$this->addType('notify', Types::BOOLEAN);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	public function getPeriod(): Period {
		$unit = PeriodUnit::from($this->periodUnit);
		return $unit === PeriodUnit::Never ? Period::never() : Period::of((int)$this->periodValue, $unit);
	}

	public function isPersonalDefault(): bool {
		return $this->folderId === null && $this->target === self::TARGET_PERSONAL;
	}

	public function toModel(): RetentionRule {
		return new RetentionRule(
			$this->id,
			$this->folderId === null ? null : (int)$this->folderId,
			$this->getPeriod(),
			$this->folderId === null ? null : Scope::from($this->scope),
			Basis::from($this->basis),
			(bool)$this->notify,
			$this->isPersonalDefault(),
		);
	}

	/** jsonSerialize() plus the translated period label ("2 weeks") */
	public function toArray(IL10N $l): array {
		return $this->jsonSerialize() + ['label' => $this->getPeriod()->label($l)];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'folderId' => $this->folderId === null ? null : (int)$this->folderId,
			// isDefault = general default rule, isPersonalDefault = default for personal folders
			'isDefault' => $this->folderId === null && !$this->isPersonalDefault(),
			'isPersonalDefault' => $this->isPersonalDefault(),
			'periodValue' => $this->periodValue === null ? null : (int)$this->periodValue,
			'periodUnit' => $this->periodUnit,
			'scope' => $this->scope,
			'basis' => $this->basis,
			'notify' => (bool)$this->notify,
			'createdBy' => $this->createdBy,
			'createdAt' => (int)$this->createdAt,
			'updatedAt' => (int)$this->updatedAt,
		];
	}
}
