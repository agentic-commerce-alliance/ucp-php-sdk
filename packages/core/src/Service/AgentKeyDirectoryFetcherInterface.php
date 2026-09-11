<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Security\AgentKeyDirectory;

interface AgentKeyDirectoryFetcherInterface
{
    public function fetch(string $uri): AgentKeyDirectory;
}
