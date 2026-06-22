<?php

declare(strict_types=1);

namespace Ucp\Sdk\Event;

use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\RequestContext;

final class CheckoutRequestReceivedEvent
{
    public function __construct(
        private CheckoutCreateRequest $request,
        private readonly RequestContext $context,
    ) {
    }

    public function getRequest(): CheckoutCreateRequest
    {
        return $this->request;
    }

    public function replaceRequest(CheckoutCreateRequest $request): void
    {
        $this->request = $request;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }
}
