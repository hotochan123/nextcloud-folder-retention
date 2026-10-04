<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Model;

use OCP\IL10N;

final class RunStats {
	public int $evaluated = 0;
	public int $due = 0;
	/** really deleted */
	public int $deleted = 0;
	/** detected as "would delete" in simulation mode */
	public int $simulated = 0;
	public int $skipped = 0;
	public int $errors = 0;
	/** retention tags newly assigned / removed */
	public int $tagsAdded = 0;
	public int $tagsRemoved = 0;
	/** false = time budget exhausted, run will be resumed */
	public bool $completed = false;
	/** true = run did not take place (last cycle too recent) */
	public bool $notDue = false;
	/** set = run did not take place because one is already running (description of the holder) */
	public ?string $lockedBy = null;
	/** areas in which nothing was deleted because of an earlier permanent deletion */
	public int $blocked = 0;
	/** true = run did not take place: background jobs run via AJAX/Webcron, not via system cron */
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
