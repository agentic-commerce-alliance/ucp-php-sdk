<?php

declare(strict_types=1);

namespace Ucp\Sdk\Capability;

use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/**
 * Advertises the AP2 mandates extension (`dev.ucp.shopping.ap2_mandate`) so it can be
 * activated through capability negotiation.
 *
 * AP2 is active for a request only when this capability is present in the negotiated
 * intersection; because it extends `dev.ucp.shopping.checkout`, the negotiator keeps it only
 * when checkout is negotiated too. The `vp_formats_supported` config declares which verifiable
 * presentation formats the business accepts for checkout mandates.
 *
 * Reference: https://ucp.dev/latest/specification/ap2-mandates/
 */
final class Ap2MandateCapability implements CapabilityInterface
{
    /**
     * @param array<string, mixed> $vpFormatsSupported
     */
    public function __construct(
        private readonly array $vpFormatsSupported = ['dc+sd-jwt' => []],
        private readonly string $version = '2026-04-08',
    ) {
    }

    public function describe(): CapabilityDescriptor
    {
        return new CapabilityDescriptor(
            UcpCapability::Ap2Mandate->value,
            $this->version,
            'https://ucp.dev/specification/ap2-mandates/',
            'https://ucp.dev/schemas/shopping/ap2_mandate.json',
            [UcpCapability::Checkout->value],
            ['vp_formats_supported' => $this->vpFormatsSupported],
        );
    }
}
