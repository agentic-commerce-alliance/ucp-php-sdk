<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

interface ProfileContributorInterface
{
    public function contribute(PlatformProfile $profile, ProfileBuildInput $input): PlatformProfile;
}
