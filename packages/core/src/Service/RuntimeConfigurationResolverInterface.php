<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Config\RuntimeConfiguration;
use Ucp\Sdk\Model\Http\HttpRequest;

interface RuntimeConfigurationResolverInterface
{
    public function resolve(HttpRequest $request): RuntimeConfiguration;
}
