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
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;
use Ucp\Sdk\Symfony\UcpSdkBundle;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new UcpSdkBundle();
    }

    public function boot(): void
    {
        parent::boot();

        $this->ensureVarDirectory();

        /** @var SchemaBootstrapper $bootstrapper */
        $bootstrapper = $this->getContainer()->get(SchemaBootstrapper::class);
        $bootstrapper->ensureSchema();
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
            'profile_fetching_development_mode' => $this->profileFetchingDevelopmentMode(),
            'signature_policy' => $this->signaturePolicy(),
            // The example provisions its own key on first use, so it boots into a state
            // where it can actually sign what it publishes. A real merchant should leave
            // this off and run `ucp:signing-keys:generate`, because a key that appears by
            // itself is a key nobody decided to trust -- which is why the SDK warns rather
            // than doing this for everyone.
            'signing_keys' => ['auto_generate' => true],
            'transports' => ['rest', 'a2a', 'embedded'],
            'storage' => [
                'dsn' => 'sqlite:///' . $this->stateDir() . '/ucp_sdk.sqlite',
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
                self::env('MERCHANT_BRAND_NAME') ?? 'Acme Outdoor',
                'EUR',
                'DE',
                self::env('MERCHANT_WEBHOOK_TARGET') ?? $baseUri . '/merchant/demo/webhook-inbox',
            ]);

        $services->set(JsonStateStore::class)
            ->arg('$stateDir', $this->stateDir());
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

    private function ensureVarDirectory(): void
    {
        foreach ([$this->getProjectDir() . '/var', $this->stateDir()] as $directory) {
            if (is_dir($directory)) {
                continue;
            }

            if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create "%s".', $directory));
            }
        }
    }

    /**
     * Where this app keeps the state it serves: the SDK's sqlite file and the JSON collections
     * behind carts, checkouts and orders.
     *
     * Overridable so a run can be given an empty directory and therefore a known starting
     * point. A conformance suite asserts on stock levels and order ids, so leftovers from a
     * previous run are the difference between a reproducible result and a confusing one.
     */
    private function stateDir(): string
    {
        $configured = self::env('UCP_MERCHANT_STATE_DIR');

        return $configured !== null
            ? rtrim($configured, '/')
            : $this->getProjectDir() . '/var';
    }

    /**
     * One reader for the environment, because there are three places to look and they disagree.
     *
     * `$_ENV` is only populated when `variables_order` says so, and under `php -S` it commonly
     * is not. Reading it alone made the console and the HTTP server resolve different state
     * directories from the same configuration -- so a signing key generated on the command line
     * landed in a database the server never opened, and the server then declined to sign
     * anything while reporting no configuration problem at all.
     */
    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function baseUri(): string
    {
        // getenv() too: whether the environment reaches `$_ENV` depends on `variables_order`,
        // and under `php -S` it commonly does not. Falling back silently to a different host
        // than the one the operator asked for changes which agents this app will talk to.
        return self::env('UCP_MERCHANT_BASE_URI') ?? 'http://localhost:8081';
    }

    private function signaturePolicy(): string
    {
        return self::env('UCP_SIGNATURE_POLICY') ?? 'log';
    }

    /**
     * On in dev, and settable in prod for one specific reason.
     *
     * Fetching a platform profile over plain http is refused outside this mode, which is
     * correct for production: a profile carries the keys used to verify every request from
     * that platform, so fetching it over a channel anyone can rewrite is worthless. The
     * upstream conformance suite serves its mock agent profile at
     * `http://localhost:<port>`, and cannot serve https, so a conformance run has no way
     * to proceed without this. That run is local development by definition.
     *
     * It is deliberately its own switch rather than a consequence of `APP_ENV`. The
     * conformance lane needs this one relaxation and nothing else that `dev` brings, and
     * a run that quietly got the whole dev container would be one that passes for reasons
     * nobody chose -- which is exactly what happened while `APP_ENV` was being read from
     * `$_SERVER` alone and silently resolving to `dev` under the built-in server.
     */
    private function profileFetchingDevelopmentMode(): bool
    {
        $explicit = self::env('UCP_PROFILE_FETCHING_DEV_MODE');
        if ($explicit !== null) {
            return filter_var($explicit, FILTER_VALIDATE_BOOL);
        }

        return $this->environment === 'dev';
    }

    /**
     * @return list<string>
     */
    private function allowedProfileHosts(string $baseUri): array
    {
        $host = parse_url($baseUri, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'localhost';

        // `localhost` and `127.0.0.1` are one host, and which of them appears here depends on
        // how this app was started rather than on who it should trust. Treating them as
        // different made the set of reachable agents an accident of configuration -- an agent
        // profile served on the other spelling was refused, and every operation after it failed
        // as an empty capability intersection.
        $loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];

        return in_array($host, $loopback, true) ? $loopback : [$host];
    }
}
