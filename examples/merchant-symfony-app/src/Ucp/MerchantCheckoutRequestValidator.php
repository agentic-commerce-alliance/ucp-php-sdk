<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\ProductCatalog;
use Ucp\Sdk\Contract\CheckoutRequestValidatorInterface;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\RequestContext;

final readonly class MerchantCheckoutRequestValidator implements CheckoutRequestValidatorInterface
{
    public function __construct(
        private ProductCatalog $catalog,
    ) {
    }

    public function validate(CheckoutCreateRequest $request, RequestContext $context): void
    {
        if ($request->lineItems === [] && $request->cartId === null) {
            throw new ValidationException('Checkout must contain at least one line item or reference an existing cart.');
        }

        $violations = [];

        foreach ($request->lineItems as $lineItem) {
            if ($lineItem->quantity < 1) {
                $violations[] = sprintf('Line item "%s" must have a quantity greater than zero.', $lineItem->id);
            }

            if ($this->catalog->find($lineItem->id) === null) {
                $violations[] = sprintf('Line item "%s" is not present in the merchant catalog.', $lineItem->id);
            }
        }

        if ($violations !== []) {
            throw new ValidationException('Checkout request failed merchant validation.', $violations);
        }
    }
}
