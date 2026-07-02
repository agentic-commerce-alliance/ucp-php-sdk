<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;

#[AsCommand(name: 'ucp:signing-keys:generate', description: 'Generate and store a signing key for the UCP SDK.')]
class GenerateSigningKeyCommand extends Command
{
    use InteractsWithSigningKeyTenant;

    public function __construct(
        private readonly SigningKeyManagerInterface $signingKeyManager,
        private readonly ManagedSigningKeyRepositoryInterface $repository,
        private readonly string $defaultKid = 'default',
        private readonly string $defaultAlgorithm = 'ES256',
        private readonly ?string $defaultRetireAfter = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kid', null, InputOption::VALUE_REQUIRED, 'Key identifier', $this->defaultKid)
            ->addOption('algorithm', null, InputOption::VALUE_REQUIRED, 'Signing algorithm', $this->defaultAlgorithm)
            ->addOption('retire-after', null, InputOption::VALUE_REQUIRED, 'Retirement interval in ISO-8601 duration form', $this->defaultRetireAfter);
        $this->configureTenantOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kid = (string) $input->getOption('kid');
        $algorithm = (string) $input->getOption('algorithm');
        $retireAfter = $input->getOption('retire-after');
        $tenantIdentifier = $this->resolveTenantIdentifier($input, $output);

        $key = $this->signingKeyManager->generate($kid, $algorithm);
        if (is_string($retireAfter) && $retireAfter !== '') {
            $retireAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->add(new \DateInterval($retireAfter))
                ->format(DATE_ATOM);
            $key = new \Ucp\Sdk\Model\Security\ManagedSigningKey(
                $key->kid,
                $key->publicKeyPem,
                $key->privateKeyPem,
                $key->algorithm,
                $key->keyType,
                $key->use,
                $key->status,
                $key->curve,
                $key->createdAt,
                $retireAt,
            );
        }
        $this->saveManagedKeyForTenant($this->repository, $tenantIdentifier, $key);

        $output->writeln(sprintf(
            'Generated signing key "%s" using %s%s.',
            $key->kid,
            $key->algorithm,
            null !== $tenantIdentifier ? sprintf(' for tenant "%s"', $tenantIdentifier) : '',
        ));

        return Command::SUCCESS;
    }
}
