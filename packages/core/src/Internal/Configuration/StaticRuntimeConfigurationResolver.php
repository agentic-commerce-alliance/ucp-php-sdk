<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Configuration;

use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\RuntimeConfigurationResolverInterface;

final readonly class StaticRuntimeConfigurationResolver implements RuntimeConfigurationResolverInterface
{
    public function __construct(
        private RuntimeConfiguration $configuration,
    ) {
    }

    public function resolve(HttpRequest $request): RuntimeConfiguration
    {
        return $this->configuration;
    }
}
