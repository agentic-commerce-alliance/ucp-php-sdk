<?php

declare(strict_types=1);

namespace MerchantSymfonyApp;

use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Ucp\Sdk\Symfony\UcpSdkBundle;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new UcpSdkBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $baseUri = $this->baseUri();

        $container->extension('framework', [
            'secret' => 'ucp-sdk-merchant',
            'test' => $this->environment === 'test',
            'http_method_override' => false,
            'router' => ['utf8' => true],
        ]);

        $container->extension('ucp_sdk', [
            'base_uri' => $baseUri,
            'allowed_profile_hosts' => $this->allowedProfileHosts($baseUri),
            'allowed_agent_domains' => $this->allowedProfileHosts($baseUri),
            'signature_policy' => $this->signaturePolicy(),
            'transports' => ['rest', 'a2a', 'embedded'],
            'storage' => [
                'dsn' => 'sqlite:///' . dirname(__DIR__) . '/var/ucp_sdk.sqlite',
            ],
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->load('MerchantSymfonyApp\\', '../src/*')
            ->exclude([
                '../src/Kernel.php',
                '../src/Resources/',
            ]);

        $services->set(MerchantSettings::class)
            ->args([
                $baseUri,
                $_ENV['MERCHANT_BRAND_NAME'] ?? $_SERVER['MERCHANT_BRAND_NAME'] ?? 'Acme Outdoor',
                'EUR',
                'DE',
                $_ENV['MERCHANT_WEBHOOK_TARGET'] ?? $_SERVER['MERCHANT_WEBHOOK_TARGET'] ?? $baseUri . '/merchant/demo/webhook-inbox',
            ]);

        $services->set(JsonStateStore::class)
            ->arg('$projectDir', dirname(__DIR__));
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/Resources/config/routes.php');
        $routes->import(dirname(__DIR__, 3) . '/packages/symfony-bundle/src/Resources/config/routes.php');
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log';
    }

    private function baseUri(): string
    {
        return $_ENV['UCP_MERCHANT_BASE_URI'] ?? $_SERVER['UCP_MERCHANT_BASE_URI'] ?? 'http://localhost:8081';
    }

    private function signaturePolicy(): string
    {
        return $_ENV['UCP_SIGNATURE_POLICY'] ?? $_SERVER['UCP_SIGNATURE_POLICY'] ?? 'log';
    }

    /**
     * @return list<string>
     */
    private function allowedProfileHosts(string $baseUri): array
    {
        $host = parse_url($baseUri, PHP_URL_HOST);

        return is_string($host) ? [$host] : ['localhost'];
    }
}
