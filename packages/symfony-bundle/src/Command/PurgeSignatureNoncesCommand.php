<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\StorageCleanupService;

#[AsCommand(name: 'ucp:storage:cleanup-signature-nonces', description: 'Purge expired signature replay nonces from the default storage adapter.')]
/** @internal */
final class PurgeSignatureNoncesCommand extends Command
{
    public function __construct(
        private readonly StorageCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than-seconds', null, InputOption::VALUE_REQUIRED, 'Delete nonces older than this many seconds.', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seconds = max(0, (int) $input->getOption('older-than-seconds'));
        $threshold = time() - $seconds;

        $this->cleanupService->cleanupSignatureNonces($threshold);

        $output->writeln(sprintf('Purged signature nonces older than %d seconds.', $seconds));

        return Command::SUCCESS;
    }
}
