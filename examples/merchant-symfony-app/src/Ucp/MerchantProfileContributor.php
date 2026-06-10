<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\MerchantSettings;
use Ucp\Sdk\Contract\ProfileContributorInterface;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

final class MerchantProfileContributor implements ProfileContributorInterface
{
    public function __construct(
        private readonly MerchantSettings $settings,
    ) {
    }

    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile
    {
        $capabilities = $profile->capabilities;
        $checkoutEntries = $capabilities['dev.ucp.shopping.checkout'] ?? [];

        if ($checkoutEntries !== []) {
            $checkoutEntries[0] = new CapabilityDescriptor(
                $checkoutEntries[0]->name,
                $checkoutEntries[0]->version,
                $checkoutEntries[0]->specUrl,
                $checkoutEntries[0]->schemaUrl,
                $checkoutEntries[0]->extends,
                array_merge($checkoutEntries[0]->config, [
                    'merchant' => [
                        'brand' => $this->settings->brandName,
                        'country' => $this->settings->country,
                        'supported_countries' => ['DE', 'AT', 'CH'],
                    ],
                ]),
            );
            $capabilities['dev.ucp.shopping.checkout'] = $checkoutEntries;
        }

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
