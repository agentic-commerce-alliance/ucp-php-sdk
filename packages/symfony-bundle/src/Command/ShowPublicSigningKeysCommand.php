<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

#[AsCommand(name: 'ucp:signing-keys:show-public', description: 'Show the public signing keys published in discovery.')]
class ShowPublicSigningKeysCommand extends Command
{
    use InteractsWithSigningKeyTenant;

    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $repository,
        private readonly SigningKeyManagerInterface $signingKeyManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->configureTenantOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenantIdentifier = $this->resolveTenantIdentifier($input, $output);

        $payload = array_map(
            fn ($key): array => $this->signingKeyManager->toPublicKey($key)->toJwk(),
            $this->activeKeysForTenant($this->repository, $tenantIdentifier),
        );

        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
