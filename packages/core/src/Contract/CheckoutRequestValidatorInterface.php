<?php

declare(strict_types=1);

namespace Ucp\Sdk\Contract;

use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\RequestContext;

interface CheckoutRequestValidatorInterface
{
    public function validate(CheckoutCreateRequest $request, RequestContext $context): void;
}
