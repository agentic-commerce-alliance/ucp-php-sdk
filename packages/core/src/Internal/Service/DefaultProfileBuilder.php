<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Contract\ProfileSigningKeyProviderInterface;
use Ucp\Sdk\Enum\Transport;
use Ucp\Sdk\Event\ProfileBuiltEvent;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\Profile\ServiceEndpoint;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;
use Ucp\Sdk\Service\ProfileBuilderInterface;

/** @internal */
final readonly class DefaultProfileBuilder implements ProfileBuilderInterface
{
    /**
     * @param iterable<ProfileContributorInterface> $contributors
     * @param iterable<ProfileSigningKeyProviderInterface> $signingKeyProviders
     */
    public function __construct(
        private CapabilityRegistryInterface $capabilityRegistry,
        private PaymentHandlerRegistryInterface $paymentHandlerRegistry,
        private iterable $contributors,
        private iterable $signingKeyProviders,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function build(ProfileBuildInput $input): PlatformProfile
    {
        $capabilities = [];
        foreach ($this->capabilityRegistry->all() as $capability) {
            $descriptor = $capability->describe();
            $capabilities[$descriptor->name] = [$descriptor];
        }

        $paymentHandlers = [];
        $context = new \Ucp\Sdk\Model\RequestContext($input->baseUri);
        foreach ($this->paymentHandlerRegistry->all() as $handler) {
            $descriptor = $handler->describe($context);
            $paymentHandlers[$descriptor->name] = [$descriptor];
        }

        $services = [
            'dev.ucp.shopping' => array_map(
                static function (Transport $transport) use ($input): ServiceEndpoint {
                    $endpoint = $input->transportEndpoints[$transport->value] ?? match ($transport) {
                        Transport::Rest => rtrim($input->baseUri, '/') . '/ucp/v1',
                        Transport::Mcp => rtrim($input->baseUri, '/') . '/ucp/mcp',
                        Transport::A2a => rtrim($input->baseUri, '/') . '/ucp/a2a',
                        Transport::Embedded => rtrim($input->baseUri, '/') . '/ucp/embedded',
                    };

                    return new ServiceEndpoint(
                        $transport,
                        $endpoint,
                        $input->version,
                        'https://ucp.dev/specification/overview/',
                        self::schemaUrl($transport, $input->version),
                    );
                },
                $input->transports,
            ),
        ];

        $signingKeys = [];
        foreach ($this->signingKeyProviders as $provider) {
            foreach ($provider->provide($input) as $key) {
                $signingKeys[] = $key;
            }
        }

        $profile = new PlatformProfile(
            $input->version,
            $services,
            $capabilities,
            $paymentHandlers,
            $signingKeys,
            $input->supportedVersions,
        );

        foreach ($this->contributors as $contributor) {
            $profile = $contributor->contribute($profile, $input);
        }

        $event = new ProfileBuiltEvent($profile, $input);
        $this->eventDispatcher->dispatch($event);

        return $event->getProfile();
    }

    private static function schemaUrl(Transport $transport, string $version): ?string
    {
        return match ($transport) {
            Transport::Rest => \sprintf('https://ucp.dev/%s/services/shopping/rest.openapi.json', $version),
            Transport::Mcp => \sprintf('https://ucp.dev/%s/services/shopping/mcp.openrpc.json', $version),
            Transport::Embedded => \sprintf('https://ucp.dev/%s/services/shopping/embedded.openrpc.json', $version),
            Transport::A2a => null,
        };
    }
}
