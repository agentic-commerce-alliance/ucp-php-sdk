<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Negotiation;

use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Contract\CartCapabilityInterface;
use Ucp\Sdk\Contract\CatalogCapabilityInterface;
use Ucp\Sdk\Contract\CheckoutCapabilityInterface;
use Ucp\Sdk\Contract\DiscountCapabilityInterface;
use Ucp\Sdk\Contract\IdentityLinkingCapabilityInterface;
use Ucp\Sdk\Contract\OrderCapabilityInterface;
use Ucp\Sdk\Contract\TokenizationCapabilityInterface;
use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\CapabilityNegotiatorInterface;
use Ucp\Sdk\Service\CapabilityRegistryInterface;
use Ucp\Sdk\Service\PaymentHandlerRegistryInterface;

/** @internal */
final class DefaultCapabilityNegotiator implements CapabilityNegotiatorInterface
{
    public function __construct(
        private readonly CapabilityRegistryInterface $capabilityRegistry,
        private readonly PaymentHandlerRegistryInterface $paymentHandlerRegistry,
    ) {
    }

    public function negotiate(?PlatformProfile $platformProfile, RequestContext $context): NegotiatedCapabilities
    {
        if ($platformProfile === null) {
            return new NegotiatedCapabilities();
        }

        $localCapabilities = [];
        $operationCapabilityMap = [];
        foreach ($this->capabilityRegistry->all() as $capability) {
            $descriptor = $capability->describe();
            if ($context->runtimeConfiguration !== null && ! $context->runtimeConfiguration->isCapabilityEnabled($descriptor->name)) {
                continue;
            }

            $localCapabilities[$descriptor->name] = $descriptor;

            foreach ($this->supportedOperations($capability) as $operation) {
                $operationCapabilityMap[$operation][] = $descriptor->name;
            }
        }

        $remoteCapabilities = array_intersect_key($platformProfile->capabilities, $localCapabilities);
        $remoteCapabilityNames = array_keys($remoteCapabilities);
        $capabilities = [];
        foreach ($remoteCapabilities as $name => $entries) {
            $capabilities[$name] = array_values(array_filter(
                $entries,
                static function (CapabilityDescriptor $descriptor) use ($remoteCapabilityNames): bool {
                    if ($descriptor->extends === null || $descriptor->extends === []) {
                        return true;
                    }

                    foreach ($descriptor->extends as $baseCapability) {
                        if (in_array($baseCapability, $remoteCapabilityNames, true)) {
                            return true;
                        }
                    }

                    return false;
                },
            ));

            if ($capabilities[$name] === []) {
                unset($capabilities[$name]);
            }
        }

        $localPaymentHandlerIds = [];
        $localPaymentHandlers = [];
        foreach ($this->paymentHandlerRegistry->all() as $handler) {
            $descriptor = $handler->describe($context);
            $localPaymentHandlerIds[] = $descriptor->id;
            $localPaymentHandlers[$descriptor->id] = $descriptor;
        }

        $remotePaymentHandlerIds = [];
        foreach ($platformProfile->paymentHandlers as $handlers) {
            foreach ($handlers as $handler) {
                $remotePaymentHandlerIds[] = $handler->id;
            }
        }

        $paymentHandlerIds = array_values(array_intersect($localPaymentHandlerIds, $remotePaymentHandlerIds));
        $paymentHandlers = [];
        foreach ($paymentHandlerIds as $id) {
            $descriptor = $localPaymentHandlers[$id] ?? null;
            if ($descriptor === null) {
                continue;
            }

            $paymentHandlers[$descriptor->name][] = $descriptor;
        }

        $negotiatedCapabilityNames = array_keys($capabilities);
        foreach ($operationCapabilityMap as $operation => $names) {
            $filtered = array_values(array_filter(
                array_values(array_unique($names)),
                static fn (string $name): bool => in_array($name, $negotiatedCapabilityNames, true),
            ));

            if ($filtered === []) {
                unset($operationCapabilityMap[$operation]);

                continue;
            }

            $operationCapabilityMap[$operation] = $filtered;
        }

        return new NegotiatedCapabilities($capabilities, $paymentHandlerIds, $operationCapabilityMap, $paymentHandlers);
    }

    /**
     * @return list<string>
     */
    private function supportedOperations(CapabilityInterface $capability): array
    {
        $operations = [];

        if ($capability instanceof CatalogCapabilityInterface) {
            $operations = [...$operations, 'catalog.search', 'catalog.lookup', 'catalog.product'];
        }

        if ($capability instanceof CartCapabilityInterface) {
            $operations = [...$operations, 'cart.create', 'cart.update', 'cart.get', 'cart.cancel'];
        }

        if ($capability instanceof DiscountCapabilityInterface) {
            $operations = [...$operations, 'discount.apply', 'cart.create', 'cart.update', 'checkout.create', 'checkout.update'];
        }

        if ($capability instanceof CheckoutCapabilityInterface) {
            $operations = [...$operations, 'checkout.create', 'checkout.update', 'checkout.get', 'checkout.complete', 'checkout.cancel'];
        }

        if ($capability instanceof TokenizationCapabilityInterface) {
            $operations[] = 'tokenization';
        }

        if ($capability instanceof IdentityLinkingCapabilityInterface) {
            $operations = [...$operations, 'oauth.metadata', 'oauth.authorize', 'oauth.token'];
        }

        if ($capability instanceof OrderCapabilityInterface) {
            $operations[] = 'order.get';
        }

        return array_values(array_unique($operations));
    }

}
