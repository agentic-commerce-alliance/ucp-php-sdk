<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Profile\PlatformProfile;

interface AgentProfileFetcherInterface
{
    public function fetch(string $uri): PlatformProfile;
}
