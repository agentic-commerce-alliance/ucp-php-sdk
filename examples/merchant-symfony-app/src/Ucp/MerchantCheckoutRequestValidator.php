<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Ucp;

use MerchantSymfonyApp\Support\ProductCatalog;
use Ucp\Sdk\Contract\CheckoutRequestValidatorInterface;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\RequestContext;

final class MerchantCheckoutRequestValidator implements CheckoutRequestValidatorInterface
{
    public function __construct(
        private readonly ProductCatalog $catalog,
    ) {
    }

    public function validate(CheckoutCreateRequest $request, RequestContext $context): void
    {
        if ($request->lineItems === [] && $request->cartId === null) {
            throw new ValidationException('Checkout must contain at least one line item or reference an existing cart.');
        }

        // A product that does not exist is not a malformed request, and the two need opposite
        // responses: a platform fixes a malformed request and resends it, while an unknown
        // product means this item cannot be bought here no matter how the request is phrased.
        // They carry different error codes precisely so that is decidable without reading prose.
        $missing = [];
        foreach ($request->lineItems as $lineItem) {
            if ($this->catalog->find($lineItem->id) === null) {
                $missing[] = $lineItem->id;
            }
        }

        if ($missing !== []) {
            throw new ResourceNotFoundException(sprintf(
                'Line %s %s was not found in the merchant catalog.',
                count($missing) === 1 ? 'item' : 'items',
                '"' . implode('", "', $missing) . '"',
            ));
        }

        $violations = [];
        foreach ($request->lineItems as $lineItem) {
            if ($lineItem->quantity < 1) {
                $violations[] = sprintf('Line item "%s" must have a quantity greater than zero.', $lineItem->id);
            }
        }

        if ($violations !== []) {
            throw new ValidationException('Checkout request failed merchant validation.', $violations);
        }
    }
}
