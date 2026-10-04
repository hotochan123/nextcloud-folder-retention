<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

use OCP\IL10N;

final class RunStats {
	public int $evaluated = 0;
	public int $due = 0;
	/** echt gelöscht */
	public int $deleted = 0;
	/** im Simulationsmodus als „würde löschen“ erkannt */
	public int $simulated = 0;
	public int $skipped = 0;
	public int $errors = 0;
	/** Aufbewahrungs-Tags neu zugewiesen / entfernt */
	public int $tagsAdded = 0;
	public int $tagsRemoved = 0;
	/** false = Zeitbudget erschöpft, Lauf wird fortgesetzt */
	public bool $completed = false;
	/** true = Lauf fand nicht statt (letzter Zyklus zu frisch) */
	public bool $notDue = false;
	/** gesetzt = Lauf fand nicht statt, weil schon einer läuft (Beschreibung des Halters) */
	public ?string $lockedBy = null;
	/** Bereiche, in denen wegen einer früheren endgültigen Löschung nichts gelöscht wurde */
	public int $blocked = 0;
	/** true = Lauf fand nicht statt: Hintergrundjobs laufen per AJAX/Webcron, nicht per System-Cron */
	public bool $notCli = false;

	/** e.g. "evaluated 6, due 2, deleted 2, …" (German: „bewertet 6, fällig 2, gelöscht 2, …“) */
	public function summary(IL10N $l): string {
		$parts = [
			$l->t('evaluated %d', [$this->evaluated]),
			$l->t('due %d', [$this->due]),
			$l->t('deleted %d', [$this->deleted]),
			$l->t('simulated %d', [$this->simulated]),
			$l->t('skipped %d', [$this->skipped]),
			$l->t('errors %d', [$this->errors]),
		];
		if ($this->tagsAdded + $this->tagsRemoved > 0) {
			$parts[] = $l->t('tags +%1$d/−%2$d', [$this->tagsAdded, $this->tagsRemoved]);
		}
		if ($this->blocked > 0) {
			$parts[] = $l->t('blocked %d', [$this->blocked]);
		}
		$summary = implode(', ', $parts);
		return $this->completed ? $summary : $l->t('%s (interrupted, will resume)', [$summary]);
	}
}
