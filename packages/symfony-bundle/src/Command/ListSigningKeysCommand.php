<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;

#[AsCommand(name: 'ucp:signing-keys:list', description: 'List managed signing keys used by the UCP SDK.')]
/** @internal */
final class ListSigningKeysCommand extends Command
{
    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keys = array_map(static fn ($key): array => [
            'kid' => $key->kid,
            'algorithm' => $key->algorithm,
            'status' => $key->status,
            'curve' => $key->curve,
            'created_at' => $key->createdAt,
            'retire_at' => $key->retireAt,
        ], $this->repository->allManaged());

        $output->writeln(json_encode($keys, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
