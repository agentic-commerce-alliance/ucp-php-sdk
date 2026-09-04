<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Ucp\Sdk\Model\Cart\CartCreateRequest;
use Ucp\Sdk\Model\Cart\CartUpdateRequest;
use Ucp\Sdk\Model\Catalog\CatalogLookupRequest;
use Ucp\Sdk\Model\Catalog\CatalogProductRequest;
use Ucp\Sdk\Model\Catalog\CatalogSearchRequest;
use Ucp\Sdk\Model\Checkout\BuyerConsent;
use Ucp\Sdk\Model\Checkout\CheckoutCompleteRequest;
use Ucp\Sdk\Model\Checkout\CheckoutCreateRequest;
use Ucp\Sdk\Model\Checkout\CheckoutUpdateRequest;
use Ucp\Sdk\Model\Checkout\DiscountCode;
use Ucp\Sdk\Model\Checkout\FulfillmentSelection;
use Ucp\Sdk\Model\Checkout\PaymentInstrument;
use Ucp\Sdk\Model\Common\Buyer;
use Ucp\Sdk\Model\Common\LineItem;
use Ucp\Sdk\Model\Common\Signals;
use Ucp\Sdk\Model\Common\Unit;
use Ucp\Sdk\Model\Common\UnitPrice;
use Ucp\Sdk\Model\Identity\OAuthAuthorizationRequest;
use Ucp\Sdk\Model\Identity\OAuthTokenRequest;

/** @internal */
final class HttpPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function decode(Request $request): array
    {
        if ($request->getContent() === '') {
            return [];
        }

        if (str_contains((string) $request->headers->get('content-type'), 'application/x-www-form-urlencoded')) {
            $payload = [];
            parse_str($request->getContent(), $payload);

            $decoded = [];
            foreach ($payload as $key => $value) {
                $decoded[(string) $key] = $value;
            }

            return $decoded;
        }

        try {
            $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BadRequestHttpException('Malformed JSON request body.', $exception);
        }

        if (! is_array($decoded)) {
            throw new BadRequestHttpException('JSON request body must be an object.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCatalogSearchRequest(array $payload): CatalogSearchRequest
    {
        return new CatalogSearchRequest((string) ($payload['query'] ?? ''), (int) ($payload['limit'] ?? 20), $payload['cursor'] ?? null, is_array($payload['filters'] ?? null) ? $payload['filters'] : []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCatalogLookupRequest(array $payload): CatalogLookupRequest
    {
        return new CatalogLookupRequest(array_values(array_map('strval', is_array($payload['ids'] ?? null) ? $payload['ids'] : [])));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCatalogProductRequest(array $payload): CatalogProductRequest
    {
        return new CatalogProductRequest(
            (string) ($payload['id'] ?? ''),
            $this->listOfArrays($payload['selected'] ?? null),
            is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
            array_values(array_map('strval', is_array($payload['preferences'] ?? null) ? $payload['preferences'] : [])),
            is_array($payload['context'] ?? null) ? $payload['context'] : [],
            is_array($payload['signals'] ?? null) ? $payload['signals'] : [],
            $this->stringMap($payload['attribution'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCartCreateRequest(array $payload): CartCreateRequest
    {
        return new CartCreateRequest($this->toLineItems($payload['line_items'] ?? []), $this->toSignals($payload['signals'] ?? null));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCartUpdateRequest(string $id, array $payload): CartUpdateRequest
    {
        return new CartUpdateRequest($id, $this->toLineItems($payload['line_items'] ?? []));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCheckoutCreateRequest(array $payload): CheckoutCreateRequest
    {
        return new CheckoutCreateRequest(
            $this->toLineItems($payload['line_items'] ?? []),
            $this->toBuyer($payload['buyer'] ?? null),
            $this->toSignals($payload['signals'] ?? null),
            $this->toDiscounts($payload['discounts']['codes'] ?? []),
            $this->toFulfillment($payload['fulfillment'] ?? null),
            $this->toConsent($payload['buyer_consent'] ?? null),
            $this->nullableString($payload['cart_id'] ?? null),
            $this->toSelectedPaymentInstrument($payload['payment'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCheckoutUpdateRequest(string $id, array $payload): CheckoutUpdateRequest
    {
        return new CheckoutUpdateRequest(
            $id,
            $this->toLineItems($payload['line_items'] ?? []),
            $this->toBuyer($payload['buyer'] ?? null),
            $this->toDiscounts($payload['discounts']['codes'] ?? []),
            $this->toFulfillment($payload['fulfillment'] ?? null),
            $this->toConsent($payload['buyer_consent'] ?? null),
            $this->toSelectedPaymentInstrument($payload['payment'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toCheckoutCompleteRequest(string $id, array $payload): CheckoutCompleteRequest
    {
        return new CheckoutCompleteRequest(
            $id,
            $this->toPaymentInstruments($payload['payment'] ?? null),
            $this->toSignals($payload['signals'] ?? null),
        );
    }

    /**
     * Reads the instrument list out of a spec-shaped payment object.
     *
     * `payment.json` defines `{"instruments": [...]}`, so an empty list means the
     * caller supplied no instrument -- which is different from supplying a broken one.
     * Passing the payment object itself to toPaymentInstrument() would read a
     * top-level `handler_id` that is not there and manufacture an instrument with an
     * empty handler id, which a mandate verifier then rejects.
     *
     * The flat single-instrument shape is still accepted, because
     * CheckoutUpdateRequest has always taken it and callers migrating between the two
     * operations should not have to notice.
     *
     * @return list<PaymentInstrument>
     */
    private function toPaymentInstruments(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload['instruments'])) {
            $instruments = [];
            foreach (is_array($payload['instruments']) ? $payload['instruments'] : [] as $instrument) {
                if (is_array($instrument)) {
                    $instruments[] = $this->toPaymentInstrument($instrument);
                }
            }

            return $instruments;
        }

        return isset($payload['handler_id']) || isset($payload['type'])
            ? [$this->toPaymentInstrument($payload)]
            : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toPaymentInstrument(array $payload): PaymentInstrument
    {
        return new PaymentInstrument(
            (string) ($payload['type'] ?? 'tokenized'),
            (string) ($payload['handler_id'] ?? ''),
            is_array($payload['credential'] ?? null) ? $payload['credential'] : [],
            is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [],
        );
    }

    /**
     * The instrument a create or update request means, out of a spec-shaped payment object.
     *
     * `payment.json` defines `{"instruments": [...]}` and the item carries `selected`, so
     * the one the buyer chose is the one to read, falling back to the first offered.
     *
     * Reading the payment object itself -- what update used to do -- looks for a top-level
     * `handler_id` the spec shape does not have, so a conformant
     * `{"instruments":[{"handler_id":"..."}]}` became `PaymentInstrument('tokenized', '')`
     * and the handler id was silently lost. `checkout.complete` was given the list-aware
     * path in #107; create and update were left behind.
     *
     * The flat single-instrument shape still works, because `CheckoutUpdateRequest` has
     * always accepted it.
     */
    private function toSelectedPaymentInstrument(mixed $payload): ?PaymentInstrument
    {
        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['instruments'])) {
            $first = null;

            foreach (is_array($payload['instruments']) ? $payload['instruments'] : [] as $instrument) {
                if (! is_array($instrument)) {
                    continue;
                }

                if (($instrument['selected'] ?? false) === true) {
                    return $this->toPaymentInstrument($instrument);
                }

                $first ??= $instrument;
            }

            return $first === null ? null : $this->toPaymentInstrument($first);
        }

        // No instrument identity anywhere means no instrument, which is distinct from a
        // broken one and better than manufacturing an empty handler id for a mandate
        // verifier to reject.
        return isset($payload['handler_id']) || isset($payload['type'])
            ? $this->toPaymentInstrument($payload)
            : null;
    }

    public function toOAuthAuthorizationRequest(Request $request): OAuthAuthorizationRequest
    {
        return new OAuthAuthorizationRequest(
            (string) $request->query->get('client_id', ''),
            (string) $request->query->get('redirect_uri', ''),
            (string) $request->query->get('scope', ''),
            (string) $request->query->get('state', ''),
            $request->query->get('code_challenge'),
            $request->query->get('code_challenge_method'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function toOAuthTokenRequest(array $payload): OAuthTokenRequest
    {
        return new OAuthTokenRequest(
            (string) ($payload['grant_type'] ?? ''),
            $payload['code'] ?? null,
            $payload['refresh_token'] ?? null,
            $payload['client_id'] ?? null,
            $payload['client_secret'] ?? null,
            $payload['code_verifier'] ?? null,
            $payload['redirect_uri'] ?? null,
        );
    }

    /**
     * @param mixed $payload
     */
    private function toBuyer(mixed $payload): ?Buyer
    {
        if (! is_array($payload)) {
            return null;
        }

        return new Buyer($payload['email'] ?? null, $payload['first_name'] ?? null, $payload['last_name'] ?? null, $payload['phone_number'] ?? null);
    }

    /**
     * @param mixed $payload
     */
    private function toSignals(mixed $payload): ?Signals
    {
        if (! is_array($payload)) {
            return null;
        }

        return new Signals($payload);
    }

    /**
     * @param mixed $payload
     * @return list<LineItem>
     */
    private function toLineItems(mixed $payload): array
    {
        $items = [];
        foreach (is_array($payload) ? $payload : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = is_array($row['item'] ?? null) ? $row['item'] : [];
            $items[] = new LineItem(
                (string) ($item['id'] ?? ''),
                (string) ($item['title'] ?? ($item['id'] ?? '')),
                (float) ($item['price'] ?? 0.0),
                (int) ($row['quantity'] ?? 1),
                $item['image_url'] ?? null,
                quantityUnit: is_array($item['quantity_unit'] ?? null) ? Unit::fromArray($item['quantity_unit']) : null,
                unitPrice: is_array($item['unit_price'] ?? null) ? UnitPrice::fromArray($item['unit_price']) : null,
            );
        }

        return $items;
    }

    /**
     * @param mixed $payload
     * @return list<array<string, mixed>>
     */
    private function listOfArrays(mixed $payload): array
    {
        $items = [];
        foreach (is_array($payload) ? $payload : [] as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @param mixed $payload
     * @return array<string, string>
     */
    private function stringMap(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $values = [];
        foreach ($payload as $key => $value) {
            $values[(string) $key] = (string) $value;
        }

        return $values;
    }

    /**
     * @param mixed $payload
     * @return list<DiscountCode>
     */
    private function toDiscounts(mixed $payload): array
    {
        $discounts = [];
        foreach (is_array($payload) ? $payload : [] as $row) {
            if (is_array($row) && isset($row['code'])) {
                $discounts[] = new DiscountCode((string) $row['code']);
            }
        }

        return $discounts;
    }

    /**
     * @param mixed $payload
     */
    private function toFulfillment(mixed $payload): ?FulfillmentSelection
    {
        if (! is_array($payload)) {
            return null;
        }

        return new FulfillmentSelection((string) ($payload['type'] ?? 'shipping'), $payload['method_id'] ?? null, $payload);
    }

    private function nullableString(mixed $payload): ?string
    {
        return is_string($payload) && $payload !== '' ? $payload : null;
    }

    /**
     * @param mixed $payload
     */
    private function toConsent(mixed $payload): ?BuyerConsent
    {
        if (! is_array($payload)) {
            return null;
        }

        return new BuyerConsent((bool) ($payload['granted'] ?? false), $payload['timestamp'] ?? null);
    }
}
