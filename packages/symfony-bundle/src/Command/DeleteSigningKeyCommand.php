<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;

#[AsCommand(name: 'ucp:signing-keys:delete', description: 'Permanently delete a signing key.')]
class DeleteSigningKeyCommand extends Command
{
    use InteractsWithSigningKeyTenant;

    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('kid', null, InputOption::VALUE_REQUIRED, 'Key identifier to delete.');
        $this->configureTenantOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kid = $input->getOption('kid');
        if (!\is_string($kid) || '' === $kid) {
            $output->writeln('<error>The --kid option is required.</error>');

            return Command::INVALID;
        }

        $tenantIdentifier = $this->resolveTenantIdentifier($input);

        if (!$this->deleteManagedKeyForTenant($this->repository, $tenantIdentifier, $kid)) {
            $output->writeln(sprintf('<error>Signing key "%s" not found.</error>', $kid));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Deleted signing key "%s".', $kid));

        return Command::SUCCESS;
    }
}
