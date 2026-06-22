<?php

declare(strict_types=1);

namespace Ucp\Sdk\Event;

use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Model\Profile\ProfileBuildInput;

final class ProfileBuiltEvent
{
    public function __construct(
        private PlatformProfile $profile,
        private readonly ProfileBuildInput $input,
    ) {
    }

    public function getProfile(): PlatformProfile
    {
        return $this->profile;
    }

    public function replaceProfile(PlatformProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function getInput(): ProfileBuildInput
    {
        return $this->input;
    }
}
