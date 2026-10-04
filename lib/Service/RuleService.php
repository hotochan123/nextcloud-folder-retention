<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Service;

use InvalidArgumentException;
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
	) {
	}

	/** @return list<Rule> Standardregeln zuerst */
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
	 * Legt die Standardregel an, falls sie fehlt: „Nie löschen“. Eine Frist muss ein Admin
	 * bewusst setzen – sonst würde das Abschalten der Simulation die ganze Instanz aufräumen.
	 * Vorhandene Regeln bleiben unverändert (auch bei Updates).
	 */
	public function ensureDefault(): Rule {
		return $this->mapper->findDefault() ?? $this->insertDefault(null, null, PeriodUnit::Never);
	}

	/**
	 * Legt die Standardregel für persönliche Ordner an, falls sie fehlt: „Nie löschen“
	 * (persönliche Ordner sind damit ausgenommen).
	 * Beim Upgrade von Versionen mit dem Schalter „Standardregel auch für persönliche Dateien“:
	 * war er an, gilt die bisherige Standardfrist weiter.
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
	 * Regel anlegen oder ändern.
	 *
	 * @param int|null $folderId null = Standardregel. Dass der Ordner existiert, prüft der Aufrufer.
	 * @param array{periodUnit?: mixed, periodValue?: mixed, scope?: mixed, basis?: mixed, notify?: mixed} $input
	 * @param bool $personal bei $folderId = null: Standardregel für persönliche Ordner
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
			$this->db->commit();
			return $rule;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** Entfernt die Regel eines Ordners; der Ordner erbt danach wieder. */
	public function delete(int $folderId): bool {
		$rule = $this->mapper->findByFolderId($folderId);
		if ($rule === null) {
			return false;
		}
		$this->mapper->delete($rule);
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
