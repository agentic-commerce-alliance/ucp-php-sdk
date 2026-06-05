<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Profile\ProfileBuildInput;
use Ucp\Sdk\Model\Security\PublicSigningKey;

interface ProfileSigningKeyProviderInterface
{
    /**
     * @return list<PublicSigningKey>
     */
    public function provide(ProfileBuildInput $input): array;
}
