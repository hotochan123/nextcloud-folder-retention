<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Command;

use OCA\FolderRetention\Model\Decision;
use OCA\FolderRetention\Model\RetentionRoot;
use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\ContentLanguage;
use OCA\FolderRetention\Service\Settings;
use OCP\IL10N;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Run extends Command {
	public function __construct(
		private RetentionRunner $runner,
		private Settings $settings,
		private IL10N $l,
		private ContentLanguage $language,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('folder_retention:run')
			->setDescription($this->l->t('Run retention now (complete, without time budget)'))
			->addOption('dry-run', null, InputOption::VALUE_NONE, $this->l->t('Only show: delete nothing, write nothing to the log'))
			->addOption('rule', null, InputOption::VALUE_REQUIRED, $this->l->t('Only files governed by the rule with this ID'))
			->addOption('unblock', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $this->l->t('Only lift the lock of this area (key as shown under “Locked”, can be repeated) – nothing is deleted'));
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$unblock = (array)$input->getOption('unblock');
		if ($unblock !== []) {
			$removed = $this->settings->unblockRoots(array_fill_keys(array_map('strval', $unblock), null));
			foreach (array_diff($unblock, $removed) as $key) {
				$output->writeln('<comment>' . $this->l->t('No lock with key %s', [$key]) . '</comment>');
			}
			foreach ($removed as $key) {
				$output->writeln('<info>' . $this->l->t('Lock lifted: %s', [$key]) . '</info>');
			}
			$this->printBlocked($output);
			return $removed === [] ? self::FAILURE : self::SUCCESS;
		}

		$dryRun = (bool)$input->getOption('dry-run');
		$ruleOpt = $input->getOption('rule');
		if ($ruleOpt !== null && !ctype_digit((string)$ruleOpt)) {
			$output->writeln('<error>' . $this->l->t('--rule expects a numeric rule ID') . '</error>');
			return self::INVALID;
		}
		$ruleId = $ruleOpt === null ? null : (int)$ruleOpt;

		if ($dryRun) {
			$output->writeln('<info>' . $this->l->t('Dry run: nothing is deleted and nothing is logged.') . '</info>');
		} elseif ($this->settings->isSimulation()) {
			$output->writeln('<comment>' . $this->l->t('Simulation mode active: due files are only written to the log.') . '</comment>');
		} else {
			$output->writeln('<error>' . $this->l->t('REAL RUN: due files are moved to the trash bin.') . '</error>');
		}

		$table = new Table($output);
		$table->setHeaders([$this->l->t('Status'), $this->l->t('File'), $this->l->t('Rule'), $this->l->t('Reference date'), $this->l->t('due since')]);
		$tz = $this->settings->timezone();
		$fmt = fn (?int $ts) => $ts === null ? '–' : (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i');

		$stats = $this->runner->runFull($dryRun, $ruleId, function (RetentionRoot $root, Decision $d, string $status, ?string $message) use ($table, $fmt) {
			$rule = $d->resolution->rule;
			$table->addRow([
				$status . ($message ? " ($message)" : ''),
				$root->displayPath($d->file->path),
				'#' . $rule->id . ' ' . $rule->logLabel($this->language->l10n()),
				$fmt($d->reference?->timestamp) . ' (' . $d->reference?->source . ')',
				$fmt($d->expiresAt),
			]);
		});

		if ($stats->lockedBy !== null) {
			if ($stats->evaluated > 0) {
				// Sperre erst während des Laufs verloren: Bis dahin Bearbeitetes trotzdem zeigen
				$table->render();
				$output->writeln($this->l->t('Result up to the abort: %s', [$stats->summary($this->l)]));
			}
			$output->writeln('<error>' . $this->l->t('A retention run is already in progress: %s.', [$stats->lockedBy]) . '</error>');
			$output->writeln($this->l->t('Try again later. If the run crashed, the lock frees itself at the latest 15 minutes after its last renewal.'));
			return self::FAILURE;
		}

		$table->render();
		$output->writeln($this->l->t('Result: %s', [$stats->summary($this->l)]));
		$this->printBlocked($output);
		return $stats->errors > 0 ? self::FAILURE : self::SUCCESS;
	}

	private function printBlocked(OutputInterface $output): void {
		$blocked = $this->settings->blockedRoots();
		foreach ($blocked as $key => $b) {
			$output->writeln('<error>' . $this->l->t('Locked: “%1$s” (key %2$s) – %3$s', [$b['label'], $key, $b['reason']]) . '</error>');
		}
		if ($blocked !== []) {
			// Gezielt je Bereich – nie alle auf einmal, sonst ginge eine eben erst gesetzte Sperre mit verloren
			$output->writeln($this->l->t('Nothing is deleted in locked areas. Find the cause, then lift the lock per area: Administration → Folder retention or'));
			$output->writeln('  occ folder_retention:run --unblock=<' . $this->l->t('key') . '>');
		}
	}
}
