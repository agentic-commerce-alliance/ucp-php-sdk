<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Enum\UcpProtocolVersion;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

final class DemoProfileContributor implements ProfileContributorInterface
{
    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        $capabilities = $profile->capabilities;
        $capabilities['dev.ucp.shopping.checkout'][0] = new CapabilityDescriptor(
            'dev.ucp.shopping.checkout',
            UcpProtocolVersion::current()->value,
            'https://ucp.dev/specification/checkout/',
            'https://ucp.dev/schemas/shopping/checkout.json',
            null,
            [
                'example' => 'bootstrap-symfony-app',
                'merchant_brand' => 'Bootstrap Demo Store',
            ],
        );

        return new PlatformProfile(
            $profile->version,
            $profile->services,
            $capabilities,
            $profile->paymentHandlers,
            $profile->signingKeys,
            $profile->supportedVersions,
        );
    }
}
