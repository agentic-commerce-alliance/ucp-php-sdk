<?php

declare(strict_types=1);

namespace BootstrapSymfonyApp\Demo;

use Ucp\Sdk\Contract\CheckoutRequestValidatorInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\RequestContext;

final class DemoCheckoutRequestValidator implements CheckoutRequestValidatorInterface
{
    public function validate(CheckoutCreateRequest $request, RequestContext $context): void
    {
        if ($request->lineItems === [] && $request->cartId === null) {
            throw new ValidationException('Checkout must contain at least one line item or reference an existing cart.');
        }
    }
}
