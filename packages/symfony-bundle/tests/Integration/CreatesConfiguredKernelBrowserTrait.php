<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use Closure;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

trait CreatesConfiguredKernelBrowserTrait
{
    /** @var list<callable> */
    private array $baselineExceptionHandlers = [];

    /**
     * @param Closure():void|null $setup
     */
    private function createConfiguredClient(?Closure $setup = null): KernelBrowser
    {
        return $this->doCreateConfiguredClient([], $setup);
    }

    /**
     * @param array<string, string> $environment
     * @param Closure():void|null $setup
     */
    private function createConfiguredClientWithEnvironment(array $environment, ?Closure $setup = null): KernelBrowser
    {
        return $this->doCreateConfiguredClient($environment, $setup);
    }

    /**
     * @param array<string, string> $environment
     * @param Closure():void|null $setup
     */
    private function doCreateConfiguredClient(array $environment, ?Closure $setup = null): KernelBrowser
    {
        self::ensureKernelShutdown();
        $this->clearExampleTestCaches();

        $restoreEnvironment = $this->applyEnvironmentOverrides($environment);

        try {
            if ($setup !== null) {
                $setup();
            }

            $client = static::createClient(['debug' => false]);
            restore_exception_handler();
        } finally {
            $restoreEnvironment();
        }

        /** @var ManagedSigningKeyRepositoryInterface $repository */
        $repository = self::getContainer()->get(ManagedSigningKeyRepositoryInterface::class);
        /** @var SigningKeyManagerInterface $manager */
        $manager = self::getContainer()->get(SigningKeyManagerInterface::class);
        $repository->saveManaged($manager->generate('demo-kid'));
        $this->seedAgentProfileCache();
        $this->baselineExceptionHandlers = $this->activeExceptionHandlers();

        return $client;
    }

    /**
     * @param array<string, string> $environment
     * @return Closure():void
     */
    private function applyEnvironmentOverrides(array $environment): Closure
    {
        /** @var array<string, array{env: string|null, server: string|null}> $previous */
        $previous = [];
        foreach ($environment as $name => $value) {
            $previous[$name] = [
                'env' => array_key_exists($name, $_ENV) ? (string) $_ENV[$name] : null,
                'server' => array_key_exists($name, $_SERVER) ? (string) $_SERVER[$name] : null,
            ];
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return static function () use ($previous): void {
            foreach ($previous as $name => $values) {
                if ($values['env'] === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $values['env'];
                }

                if ($values['server'] === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $values['server'];
                }
            }
        };
    }

    private function clearExampleTestCaches(): void
    {
        foreach ([
            dirname(__DIR__, 4) . '/examples/bootstrap-symfony-app/var/cache/test',
            dirname(__DIR__, 4) . '/examples/merchant-symfony-app/var/cache/test',
        ] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());

                    continue;
                }

                @unlink($item->getPathname());
            }

            @rmdir($directory);
        }

        foreach ([
            dirname(__DIR__, 4) . '/examples/bootstrap-symfony-app/var/ucp_sdk.sqlite',
            dirname(__DIR__, 4) . '/examples/merchant-symfony-app/var/ucp_sdk.sqlite',
        ] as $databaseFile) {
            @unlink($databaseFile);
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    private function request(KernelBrowser $client, string $method, string $uri, array $server = [], ?string $content = null): void
    {
        $server = $this->withDefaultRuntimeAgentHeader($uri, $server);

        $client->request($method, $uri, server: $server, content: $content);
        $this->restoreExceptionHandlers($this->baselineExceptionHandlers);
    }

    private function seedAgentProfileCache(): void
    {
        /** @var UcpSdkConfiguration $configuration */
        $configuration = self::getContainer()->get(UcpSdkConfiguration::class);
        $baseUri = $configuration->resolvedBaseUri();
        $profileUri = $this->testAgentProfileUri($baseUri);

        /** @var ProfileBuilderInterface $profileBuilder */
        $profileBuilder = self::getContainer()->get(ProfileBuilderInterface::class);
        /** @var PlatformProfileCacheRepositoryInterface $cacheRepository */
        $cacheRepository = self::getContainer()->get(PlatformProfileCacheRepositoryInterface::class);
        $cacheRepository->save($profileUri, $profileBuilder->build(new ProfileBuildInput(
            $configuration->version,
            $baseUri,
            $configuration->transports,
            supportedVersions: $configuration->supportedVersions,
            transportEndpoints: $configuration->transportEndpoints,
            enabledCapabilities: $configuration->enabledCapabilities,
        )));
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private function withDefaultRuntimeAgentHeader(string $uri, array $server): array
    {
        if (! str_starts_with($uri, '/ucp/v1/') || array_key_exists('HTTP_UCP_AGENT', $server)) {
            return $server;
        }

        /** @var UcpSdkConfiguration $configuration */
        $configuration = self::getContainer()->get(UcpSdkConfiguration::class);
        $server['HTTP_UCP_AGENT'] = sprintf('platform; profile="%s"', $this->testAgentProfileUri($configuration->resolvedBaseUri()));

        return $server;
    }

    private function testAgentProfileUri(string $baseUri): string
    {
        $parts = parse_url($baseUri);
        $host = $parts['host'] ?? 'localhost';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('https://%s%s/.well-known/ucp', $host, $port);
    }

    /**
     * @return list<callable>
     */
    private function activeExceptionHandlers(): array
    {
        $handlers = [];

        while (true) {
            $previousHandler = set_exception_handler(static fn () => null);
            restore_exception_handler();

            if ($previousHandler === null) {
                break;
            }

            $handlers[] = $previousHandler;
            restore_exception_handler();
        }

        $handlers = array_reverse($handlers);

        foreach ($handlers as $handler) {
            set_exception_handler($handler);
        }

        return $handlers;
    }

    /**
     * @param list<callable> $baselineHandlers
     */
    private function restoreExceptionHandlers(array $baselineHandlers): void
    {
        $currentHandlers = $this->activeExceptionHandlers();

        if ($currentHandlers === $baselineHandlers) {
            return;
        }

        foreach ($currentHandlers as $_handler) {
            restore_exception_handler();
        }

        foreach ($baselineHandlers as $handler) {
            set_exception_handler($handler);
        }
    }
}
