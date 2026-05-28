<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;

interface HttpRequestContextFactoryInterface
{
    public function create(HttpRequest $request): RequestContext;
}
