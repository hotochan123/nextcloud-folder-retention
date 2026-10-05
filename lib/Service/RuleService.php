<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use InvalidArgumentException;
use OCA\FolderRetention\Db\LogMapper;
use OCA\FolderRetention\Db\Rule;
use OCA\FolderRetention\Db\RuleMapper;
use OCA\FolderRetention\Model\Basis;
use OCA\FolderRetention\Model\Period;
use OCA\FolderRetention\Model\PeriodUnit;
use OCA\FolderRetention\Model\RuleSet;
use OCA\FolderRetention\Model\Scope;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

class RuleService {
	public function __construct(
		private RuleMapper $mapper,
		private IDBConnection $db,
		private ITimeFactory $time,
		private Settings $settings,
		private IL10N $l,
		private LogMapper $logMapper,
	) {
	}

	/** @return list<Rule> default rules first */
	public function list(): array {
		$this->ensureDefault();
		$this->ensurePersonalDefault();
		$rules = $this->mapper->findAll();
		usort($rules, fn (Rule $a, Rule $b) => ($a->getFolderId() === null ? 0 : 1) <=> ($b->getFolderId() === null ? 0 : 1));
		return $rules;
	}

	public function snapshot(): RuleSet {
		$default = null;
		$personal = null;
		$byFolder = [];
		foreach ($this->list() as $rule) {
			$model = $rule->toModel();
			if ($model->personal) {
				$personal = $model;
			} elseif ($model->isDefault()) {
				$default = $model;
			} else {
				$byFolder[$model->folderId] = $model;
			}
		}
		return new RuleSet($default, $byFolder, $personal);
	}

	/**
	 * Creates the default rule if it is missing: "Never delete". A retention period must be set
	 * deliberately by an admin – otherwise switching off simulation would clean up the whole instance.
	 * Existing rules remain unchanged (also on updates).
	 */
	public function ensureDefault(): Rule {
		return $this->mapper->findDefault() ?? $this->insertDefault(null, null, PeriodUnit::Never);
	}

	/**
	 * Creates the default rule for personal folders if it is missing: "Never delete"
	 * (personal folders are thus excluded).
	 * When upgrading from versions with the switch "Default rule also for personal files":
	 * if it was on, the previous default retention period continues to apply.
	 */
	public function ensurePersonalDefault(): Rule {
		$rule = $this->mapper->findDefault(true);
		if ($rule !== null) {
			return $rule;
		}
		if ($this->settings->includePersonal()) {
			$general = $this->ensureDefault();
			return $this->insertDefault(Rule::TARGET_PERSONAL, $general->getPeriodValue(), PeriodUnit::from($general->getPeriodUnit()));
		}
		return $this->insertDefault(Rule::TARGET_PERSONAL, null, PeriodUnit::Never);
	}

	private function insertDefault(?string $target, ?int $value, PeriodUnit $unit): Rule {
		$now = $this->time->getTime();
		$rule = new Rule();
		$rule->setFolderId(null);
		$rule->setTarget($target);
		$rule->setPeriodValue($unit === PeriodUnit::Never ? null : $value);
		$rule->setPeriodUnit($unit->value);
		$rule->setScope(null);
		$rule->setBasis(Basis::Created->value);
		$rule->setNotify(false);
		$rule->setCreatedBy(null);
		$rule->setCreatedAt($now);
		$rule->setUpdatedAt($now);
		return $this->mapper->insert($rule);
	}

	/**
	 * Create or update a rule. If what decides deletion changes (period, scope, basis), the
	 * simulated hits evaluated with the old setting are marked as superseded.
	 *
	 * @param int|null $folderId null = default rule. The caller checks that the folder exists.
	 * @param array{periodUnit?: mixed, periodValue?: mixed, scope?: mixed, basis?: mixed, notify?: mixed} $input
	 * @param bool $personal with $folderId = null: default rule for personal folders
	 */
	public function upsert(?int $folderId, array $input, ?string $userId, bool $personal = false): Rule {
		[$period, $scope, $basis, $notify] = $this->validate($folderId, $input);

		$this->db->beginTransaction();
		try {
			$rule = $folderId === null
				? ($personal ? $this->ensurePersonalDefault() : $this->ensureDefault())
				: $this->mapper->findByFolderId($folderId);
			$now = $this->time->getTime();
			$isNew = $rule === null;
			$before = $isNew ? null : [$rule->getPeriodUnit(), $rule->getPeriodValue() === null ? null : (int)$rule->getPeriodValue(), $rule->getScope(), $rule->getBasis()];
			if ($isNew) {
				$rule = new Rule();
				$rule->setFolderId($folderId);
				$rule->setCreatedBy($userId);
				$rule->setCreatedAt($now);
			}
			$rule->setPeriodUnit($period->unit->value);
			$rule->setPeriodValue($period->value);
			$rule->setScope($scope?->value);
			$rule->setBasis($basis->value);
			$rule->setNotify($notify);
			$rule->setUpdatedAt($now);
			$rule = $isNew ? $this->mapper->insert($rule) : $this->mapper->update($rule);
			if ($before !== null && $before !== [$period->unit->value, $period->value, $scope?->value, $basis->value]) {
				$this->logMapper->supersedeByRule($rule->getId(), $now);
			}
			$this->db->commit();
			return $rule;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Removes a folder's rule; the folder inherits again afterwards. Its simulated hits are superseded. */
	public function delete(int $folderId): bool {
		$rule = $this->mapper->findByFolderId($folderId);
		if ($rule === null) {
			return false;
		}
		$this->mapper->delete($rule);
		$this->logMapper->supersedeByRule($rule->getId(), $this->time->getTime());
		return true;
	}

	/**
	 * @return array{Period, ?Scope, Basis, bool}
	 */
	private function validate(?int $folderId, array $input): array {
		$unit = PeriodUnit::tryFrom((string)($input['periodUnit'] ?? ''));
		if ($unit === null) {
			throw new ValidationException($this->l->t('periodUnit must be day, week, month or never'));
		}
		if ($unit === PeriodUnit::Never) {
			$period = Period::never();
		} else {
			$value = $input['periodValue'] ?? null;
			if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
				throw new ValidationException($this->l->t('periodValue must be a whole number'));
			}
			if ((int)$value < 1 || (int)$value > Period::MAX_VALUE) {
				throw new ValidationException($this->l->t('Period must be between 1 and %d', [Period::MAX_VALUE]));
			}
			try {
				$period = Period::of((int)$value, $unit);
			} catch (InvalidArgumentException $e) {
				throw new ValidationException($e->getMessage());
			}
		}

		$scope = null;
		if ($folderId !== null) {
			$scope = Scope::tryFrom((string)($input['scope'] ?? Scope::Inherit->value));
			if ($scope === null) {
				throw new ValidationException($this->l->t('scope must be inherit or here'));
			}
		}

		$basis = Basis::tryFrom((string)($input['basis'] ?? Basis::Created->value));
		if ($basis === null) {
			throw new ValidationException($this->l->t('basis must be created or modified'));
		}

		$notify = filter_var($input['notify'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($notify === null) {
			throw new ValidationException($this->l->t('notify must be true or false'));
		}

		return [$period, $scope, $basis, $notify];
	}
}
