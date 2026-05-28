<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Negotiation\NegotiatedCapabilities;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\RequestContext;

interface CapabilityNegotiatorInterface
{
    public function negotiate(?PlatformProfile $platformProfile, RequestContext $context): NegotiatedCapabilities;
}
