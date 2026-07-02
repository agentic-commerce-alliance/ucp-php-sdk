<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;

#[AsCommand(name: 'ucp:signing-keys:retire', description: 'Retire a signing key (kept for verification, no longer used to sign).')]
class RetireSigningKeyCommand extends Command
{
    use InteractsWithSigningKeyTenant;

    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('kid', null, InputOption::VALUE_REQUIRED, 'Key identifier to retire.');
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

        $existing = $this->findManagedKeyForTenant($this->repository, $tenantIdentifier, $kid);
        if (null === $existing) {
            $output->writeln(sprintf('<error>Signing key "%s" not found.</error>', $kid));

            return Command::FAILURE;
        }

        $this->saveManagedKeyForTenant($this->repository, $tenantIdentifier, new ManagedSigningKey(
            $existing->kid,
            $existing->publicKeyPem,
            $existing->privateKeyPem,
            $existing->algorithm,
            $existing->keyType,
            $existing->use,
            'retired',
            $existing->curve,
            $existing->createdAt,
            gmdate('c'),
        ));

        $output->writeln(sprintf('Retired signing key "%s".', $kid));

        return Command::SUCCESS;
    }
}
