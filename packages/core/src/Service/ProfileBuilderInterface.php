<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

interface ProfileBuilderInterface
{
    public function build(ProfileBuildInput $input): PlatformProfile;
}
