<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCP\IL10N;

/**
 * Aufbewahrungsfrist, z. B. „2 Wochen“ oder „nie löschen“.
 */
final class Period {
	public const MAX_VALUE = 3650;

	private function __construct(
		public readonly PeriodUnit $unit,
		public readonly ?int $value,
	) {
	}

	public static function of(int $value, PeriodUnit $unit): self {
		if ($unit === PeriodUnit::Never) {
			return self::never();
		}
		if ($value < 1 || $value > self::MAX_VALUE) {
			throw new InvalidArgumentException('Period must be between 1 and ' . self::MAX_VALUE);
		}
		return new self($unit, $value);
	}

	public static function never(): self {
		return new self(PeriodUnit::Never, null);
	}

	public function isNever(): bool {
		return $this->unit === PeriodUnit::Never;
	}

	/**
	 * Zeitpunkt, ab dem eine Datei mit Bezugsdatum $reference gelöscht werden darf.
	 * Kalendarisch in $tz gerechnet (Sommerzeit!). Monate werden auf das Monatsende
	 * begrenzt: 31.01. + 1 Monat = 28./29.02., nicht 03.03.
	 *
	 * @return int|null Unix-Timestamp, null bei „nie löschen“
	 */
	public function expiresAt(int $reference, DateTimeZone $tz): ?int {
		if ($this->isNever()) {
			return null;
		}
		$start = (new DateTimeImmutable('@' . $reference))->setTimezone($tz);

		if ($this->unit === PeriodUnit::Month) {
			$y = (int)$start->format('Y');
			$m = (int)$start->format('n') + $this->value;
			$y += intdiv($m - 1, 12);
			$m = (($m - 1) % 12) + 1;
			$lastDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $tz))->format('t');
			$d = min((int)$start->format('j'), $lastDay);
			return $start->setDate($y, $m, $d)->getTimestamp();
		}

		$days = $this->unit === PeriodUnit::Week ? $this->value * 7 : $this->value;
		return $start->modify('+' . $days . ' days')->getTimestamp();
	}

	/** Short form for badge/log/tag, e.g. "1 month", "2 weeks", "Never delete" (German: „1 Monat“, „2 Wochen“, „Nie löschen“) */
	public function label(IL10N $l): string {
		if ($this->isNever()) {
			return $l->t('Never delete');
		}
		$v = (int)$this->value;
		return match ($this->unit) {
			PeriodUnit::Day => $l->n('%n day', '%n days', $v),
			PeriodUnit::Week => $l->n('%n week', '%n weeks', $v),
			PeriodUnit::Month => $l->n('%n month', '%n months', $v),
		};
	}

	public function equals(self $other): bool {
		return $this->unit === $other->unit && $this->value === $other->value;
	}
}
