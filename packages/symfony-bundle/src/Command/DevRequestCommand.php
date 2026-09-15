<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/**
 * Prints a ready-to-run `curl` for a UCP operation against this deployment, with the
 * deployment's own discovery profile as the agent.
 *
 * The first request against a fresh install is where new adopters stall: every runtime request
 * must name an agent profile URL, and the SSRF rules refuse everything a laptop can offer. In
 * development mode the SDK accepts its own `/.well-known/ucp` as that profile, and this command
 * writes out exactly the request that exercises it, headers and sample body included, so the
 * path from "installed" to "first 200" is one copy and paste.
 *
 * @internal
 */
#[AsCommand(name: 'ucp:dev:request', description: 'Print a ready-to-run curl request for a UCP operation against this deployment, using its own profile as the agent (development mode).')]
final class DevRequestCommand extends Command
{
    private const AGENT_NAME = 'dev-console';

    /**
     * Operation => [method, path, sample body]. `{id}` in the path or body is replaced by
     * `--id`. Bodies are the smallest payloads the request schemas accept; `checkout.complete`
     * is deliberately absent, since its payment payload depends on the handler the business
     * publishes and no generic sample would validate.
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, mixed>|null}>
     */
    private const OPERATIONS = [
        'profile' => ['GET', '/.well-known/ucp', null],
        'catalog.search' => ['POST', '/ucp/v1/catalog/search', ['query' => 'tent']],
        'catalog.lookup' => ['POST', '/ucp/v1/catalog/lookup', ['ids' => ['{id}']]],
        'catalog.product' => ['POST', '/ucp/v1/catalog/product', ['id' => '{id}']],
        'cart.create' => ['POST', '/ucp/v1/carts', ['line_items' => [['item' => ['id' => '{id}'], 'quantity' => 1]]]],
        'cart.get' => ['GET', '/ucp/v1/carts/{id}', null],
        'cart.update' => ['PUT', '/ucp/v1/carts/{id}', ['line_items' => [['item' => ['id' => '{id}'], 'quantity' => 2]]]],
        'cart.cancel' => ['POST', '/ucp/v1/carts/{id}/cancel', null],
        'checkout.create' => ['POST', '/ucp/v1/checkout-sessions', ['line_items' => [['item' => ['id' => '{id}'], 'quantity' => 1]]]],
        'checkout.get' => ['GET', '/ucp/v1/checkout-sessions/{id}', null],
        'checkout.update' => ['PUT', '/ucp/v1/checkout-sessions/{id}', ['line_items' => [['item' => ['id' => '{id}'], 'quantity' => 1]]]],
        'checkout.cancel' => ['POST', '/ucp/v1/checkout-sessions/{id}/cancel', null],
        'order.get' => ['GET', '/ucp/v1/orders/{id}', null],
    ];

    public function __construct(private readonly UcpSdkConfiguration $configuration)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('operation', InputArgument::OPTIONAL, 'Operation to print, e.g. catalog.search. Omit to list them all.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Resource or product id substituted for {id} in the path and body.')
            ->addOption('base-uri', null, InputOption::VALUE_REQUIRED, 'Deployment base URI. Defaults to ucp_sdk.base_uri.')
            ->setHelp(<<<'HELP'
                Prints a <info>curl</info> command for one UCP operation against this deployment. The
                <info>UCP-Agent</info> header points at the deployment's own <info>/.well-known/ucp</info>, which the
                SDK accepts as the agent profile while <info>ucp_sdk.profile_fetching_development_mode</info>
                is on, so no second server is needed to make a first request.

                  <info>bin/console ucp:dev:request</info>                          list operations
                  <info>bin/console ucp:dev:request catalog.search</info>           print the search request
                  <info>bin/console ucp:dev:request cart.create --id=SW10001</info>  with a real product id
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $baseUri = rtrim((string) ($input->getOption('base-uri') ?? $this->configuration->resolvedBaseUri()), '/');
        if ($baseUri === '') {
            $io->error('No base URI. Set ucp_sdk.base_uri or pass --base-uri=https://shop.example.');

            return Command::INVALID;
        }

        if (! $this->configuration->profileFetchingDevelopmentMode) {
            $io->warning([
                'ucp_sdk.profile_fetching_development_mode is off, so this deployment will refuse its own profile as the agent',
                'and the request below will fail with "Platform profile host is not allowed". Turn the setting on for local',
                'development only; it also admits plain http and loopback profile hosts.',
            ]);
        }

        $operation = $input->getArgument('operation');
        if (! is_string($operation) || $operation === '') {
            $io->title('UCP operations');
            $io->table(['Operation', 'Method', 'Path'], array_map(
                static fn (string $name, array $definition): array => [$name, $definition[0], $definition[1]],
                array_keys(self::OPERATIONS),
                self::OPERATIONS,
            ));
            $io->text('Run <info>ucp:dev:request &lt;operation&gt;</info> to print the request. Operations with <info>{id}</info> take <info>--id</info>.');

            return Command::SUCCESS;
        }

        if (! isset(self::OPERATIONS[$operation])) {
            $io->error(sprintf('Unknown operation "%s". Run the command without arguments to list them.', $operation));

            return Command::INVALID;
        }

        [$method, $path, $body] = self::OPERATIONS[$operation];
        $id = (string) ($input->getOption('id') ?? '<id>');

        $lines = [
            sprintf("curl -sS -X %s '%s%s'", $method, $baseUri, str_replace('{id}', $id, $path)),
            sprintf("  -H 'UCP-Agent: %s; profile=\"%s/.well-known/ucp\"'", self::AGENT_NAME, $baseUri),
            "  -H 'Accept: application/json'",
        ];

        if ($method !== 'GET') {
            $lines[] = "  -H 'Content-Type: application/json'";
            $lines[] = sprintf("  -H 'Idempotency-Key: dev-%s'", bin2hex(random_bytes(6)));
        }

        if ($body !== null) {
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $lines[] = sprintf("  --data '%s'", str_replace('{id}', $id, $json));
        }

        $output->writeln(implode(" \\\n", $lines));

        if ($id === '<id>' && (str_contains($path, '{id}') || ($body !== null && str_contains(json_encode($body, JSON_THROW_ON_ERROR), '{id}')))) {
            $io->newLine();
            $io->text('Replace <info>&lt;id&gt;</info> with a real id, or pass <info>--id</info>.');
        }

        return Command::SUCCESS;
    }
}
