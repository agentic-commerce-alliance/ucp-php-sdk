<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\StorageCleanupService;

#[AsCommand(name: 'ucp:storage:cleanup', description: 'Purge expired records from the default SDK storage adapter.')]
final class StorageCleanupCommand extends Command
{
    public function __construct(
        private readonly StorageCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cleanupService->cleanup();
        $output->writeln('<info>Expired SDK storage records purged.</info>');

        return Command::SUCCESS;
    }
}
