<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Profile\PlatformProfile;

interface AgentProfileFetcherInterface
{
    /**
     * @throws ValidationException   when the URI is unsafe, or the fetched profile is malformed
     * @throws AgentProfileException when the profile cannot be fetched or is not a profile document
     */
    public function fetch(string $uri): PlatformProfile;
}
