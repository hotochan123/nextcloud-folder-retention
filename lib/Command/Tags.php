<?php

declare(strict_types=1);

namespace OCA\FolderRetention\Command;

use OCA\FolderRetention\Service\RetentionRunner;
use OCA\FolderRetention\Service\Settings;
use OCA\FolderRetention\Service\TagService;
use OCP\IL10N;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Tags extends Command {
	public function __construct(
		private RetentionRunner $runner,
		private TagService $tags,
		private Settings $settings,
		private IL10N $l,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('folder_retention:tags')
			->setDescription($this->l->t('Sync retention tags on files and folders now (deletes nothing)'))
			->addOption('folder', null, InputOption::VALUE_REQUIRED, $this->l->t('Only this folder (ID) and its content'))
			->addOption('remove', null, InputOption::VALUE_NONE, $this->l->t('Remove all retention tags from all files'));
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('remove')) {
			$n = $this->tags->removeAll();
			$output->writeln($this->l->n('%n tag assignment removed.', '%n tag assignments removed.', $n));
			if ($this->settings->tagsEnabled()) {
				$output->writeln('<comment>' . $this->l->t('Tags are still switched on – the next run sets them again. Switch off: %s', ['occ config:app:set folder_retention tags_enabled --value=0 --type=boolean']) . '</comment>');
			}
			return self::SUCCESS;
		}
		if (!$this->settings->tagsEnabled()) {
			$output->writeln('<error>' . $this->l->t('Tags are switched off (setting tags_enabled).') . '</error>');
			return self::FAILURE;
		}
		$folder = $input->getOption('folder');
		if ($folder !== null && !ctype_digit((string)$folder)) {
			$output->writeln('<error>' . $this->l->t('--folder expects a numeric folder ID') . '</error>');
			return self::INVALID;
		}
		$stats = $this->runner->syncTags($folder === null ? null : (int)$folder);
		$output->writeln($this->l->t('Synced: %1$d files evaluated, %2$d tags set, %3$d removed.', [$stats->evaluated, $stats->tagsAdded, $stats->tagsRemoved]));
		return $stats->completed ? self::SUCCESS : self::FAILURE;
	}
}
