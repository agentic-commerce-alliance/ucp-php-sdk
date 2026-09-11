<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Ucp\Sdk\Model\Checkout\BuyerConsent;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

final class HttpPayloadMapperTest extends TestCase
{
    #[Test]
    public function itDecodesFormEncodedOAuthTokenPayloads(): void
    {
        $request = Request::create(
            '/ucp/v1/oauth/token',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => 'ucp_code_123',
                'client_id' => 'https://agent.example/profile.json',
                'redirect_uri' => 'https://agent.example/callback',
                'code_verifier' => 'verifier',
            ]),
        );

        $mapper = new HttpPayloadMapper();
        $tokenRequest = $mapper->toOAuthTokenRequest($mapper->decode($request));

        self::assertSame('authorization_code', $tokenRequest->grantType);
        self::assertSame('ucp_code_123', $tokenRequest->code);
        self::assertSame('https://agent.example/profile.json', $tokenRequest->clientId);
        self::assertSame('https://agent.example/callback', $tokenRequest->redirectUri);
        self::assertSame('verifier', $tokenRequest->codeVerifier);
    }

    #[Test]
    public function itMapsCheckoutCreateCartIdAndFulfillmentAddress(): void
    {
        $mapper = new HttpPayloadMapper();

        $checkoutRequest = $mapper->toCheckoutCreateRequest([
            'cart_id' => 'cart-123',
            'buyer' => [
                'email' => 'buyer@example.test',
                'first_name' => 'Alex',
                'last_name' => 'Summit',
            ],
            'fulfillment' => [
                'type' => 'shipping',
                'shipping_address' => [
                    'street' => 'Test Street 1',
                    'zipcode' => '12345',
                    'city' => 'Berlin',
                    'country_code' => 'DE',
                ],
            ],
        ]);

        self::assertSame('cart-123', $checkoutRequest->cartId);
        self::assertSame('buyer@example.test', $checkoutRequest->buyer?->email);
        self::assertSame('shipping', $checkoutRequest->fulfillment?->type);
        self::assertSame('Test Street 1', $checkoutRequest->fulfillment?->extra['shipping_address']['street'] ?? null);
    }

    #[Test]
    public function itReadsTheSelectedInstrumentOutOfASpecShapedPayment(): void
    {
        $mapper = new HttpPayloadMapper();

        $payment = [
            'instruments' => [
                ['id' => 'pi-1', 'handler_id' => 'com.example.card', 'type' => 'card'],
                [
                    'id' => 'pi-2',
                    'handler_id' => 'com.shopware.invoice',
                    'type' => 'delegated',
                    'selected' => true,
                    'billing_address' => [
                        'street_address' => 'Billing Street 2',
                        'address_locality' => 'Hamburg',
                        'postal_code' => '20095',
                        'address_country' => 'DE',
                    ],
                ],
            ],
        ];

        // `selected` decides, not position. This used to read a top-level handler_id the
        // spec shape does not have and produce PaymentInstrument('tokenized', '').
        foreach ([
            $mapper->toCheckoutUpdateRequest('checkout-1', ['payment' => $payment])->payment,
            $mapper->toCheckoutCreateRequest(['payment' => $payment])->payment,
        ] as $instrument) {
            self::assertNotNull($instrument);
            self::assertSame('com.shopware.invoice', $instrument->handlerId);
            self::assertSame('delegated', $instrument->type);
            self::assertSame('Billing Street 2', $instrument->billingAddress['street_address'] ?? null);
        }
    }

    #[Test]
    public function itFallsBackToTheFirstInstrumentWhenNoneIsSelected(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutUpdateRequest('checkout-1', [
            'payment' => ['instruments' => [['handler_id' => 'com.example.card', 'type' => 'card']]],
        ]);

        self::assertSame('com.example.card', $request->payment?->handlerId);
    }

    #[Test]
    public function itStillAcceptsTheFlatSingleInstrumentShape(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutUpdateRequest('checkout-1', [
            'payment' => ['handler_id' => 'com.example.card', 'type' => 'card'],
        ]);

        self::assertSame('com.example.card', $request->payment?->handlerId);
    }

    #[Test]
    public function itReportsNoInstrumentRatherThanOneWithAnEmptyHandlerId(): void
    {
        $mapper = new HttpPayloadMapper();

        // An empty instrument list, and a payment object naming no instrument at all,
        // both mean "no instrument" -- manufacturing one with an empty handler id only
        // gives a mandate verifier something to reject.
        self::assertNull($mapper->toCheckoutUpdateRequest('c', ['payment' => ['instruments' => []]])->payment);
        self::assertNull($mapper->toCheckoutUpdateRequest('c', ['payment' => ['method' => 'invoice']])->payment);
        self::assertNull($mapper->toCheckoutUpdateRequest('c', [])->payment);
        self::assertNull($mapper->toCheckoutCreateRequest([])->payment);
    }

    #[Test]
    public function itKeepsTheBillingAddressOnEveryCompletionInstrument(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutCompleteRequest('checkout-1', [
            'payment' => ['instruments' => [[
                'handler_id' => 'com.shopware.invoice',
                'type' => 'delegated',
                'billing_address' => ['street_address' => 'Billing Street 2', 'postal_code' => '20095'],
            ]]],
        ]);

        self::assertCount(1, $request->instruments);
        self::assertSame('20095', $request->instruments[0]->billingAddress['postal_code'] ?? null);
    }

    #[Test]
    public function itRejectsMalformedJsonPayloadsAsBadRequests(): void
    {
        $mapper = new HttpPayloadMapper();
        $request = Request::create('/ucp/v1/catalog/search', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{');

        try {
            $mapper->decode($request);
            self::fail('Expected malformed JSON to be rejected.');
        } catch (BadRequestHttpException $exception) {
            self::assertSame('Malformed JSON request body.', $exception->getMessage());
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function itRejectsTopLevelScalarJsonPayloadsAsBadRequests(): void
    {
        $mapper = new HttpPayloadMapper();
        $request = Request::create('/ucp/v1/catalog/search', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '123');

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('JSON request body must be an object.');

        $mapper->decode($request);
    }

    #[Test]
    public function itReadsConsentFromTheBuyerObjectWhereTheSchemaPutsIt(): void
    {
        // Every published schema locates consent at buyer.consent. This mapper used to read a
        // top-level buyer_consent key that no schema defines, so a conformant request arrived
        // with its consent silently discarded.
        $request = (new HttpPayloadMapper())->toCheckoutCreateRequest([
            'line_items' => [],
            'buyer' => [
                'email' => 'buyer@example.com',
                'consent' => [
                    BuyerConsent::PURPOSE_MARKETING => [
                        'granted' => true,
                        'source' => 'business',
                        'description' => 'Promotional email',
                    ],
                ],
            ],
        ]);

        self::assertNotNull($request->consent);
        self::assertTrue($request->consent->granted(BuyerConsent::PURPOSE_MARKETING));
    }

    #[Test]
    public function itStillAcceptsTheTopLevelBuyerConsentKeyForOneRelease(): void
    {
        // Not conformant, but this SDK advertised it in its own MCP tool schemas, so adopters
        // may be sending it. Removed at the 2026-08-25 flip.
        $request = (new HttpPayloadMapper())->toCheckoutUpdateRequest('checkout_1', [
            'buyer_consent' => ['marketing' => true],
        ]);

        self::assertNotNull($request->consent);
        self::assertTrue($request->consent->granted(BuyerConsent::PURPOSE_MARKETING));
    }

    #[Test]
    public function theBuyerObjectWinsOverTheLegacyTopLevelKey(): void
    {
        $request = (new HttpPayloadMapper())->toCheckoutCreateRequest([
            'buyer' => ['consent' => ['marketing' => false]],
            'buyer_consent' => ['marketing' => true],
        ]);

        self::assertNotNull($request->consent);
        self::assertFalse($request->consent->granted(BuyerConsent::PURPOSE_MARKETING));
    }

    #[Test]
    public function aRequestWithoutConsentCarriesNone(): void
    {
        $request = (new HttpPayloadMapper())->toCheckoutCreateRequest([
            'buyer' => ['email' => 'buyer@example.com'],
        ]);

        self::assertNull($request->consent);
    }

    /**
     * Signals are an agent's hints about the shopping context, and no SDK code reads them
     * back -- they exist to be handed to a capability implementation, which is why nothing
     * exercised Signals::toArray(). That makes the pass-through the whole contract: what
     * the agent sent has to arrive intact and readable, on every request that accepts it.
     */
    #[Test]
    public function itCarriesSignalsThroughToEveryRequestThatAcceptsThem(): void
    {
        $mapper = new HttpPayloadMapper();
        $signals = ['referrer' => 'https://agent.example', 'session_count' => 3, 'returning' => true];
        $lineItems = [[
            'item' => ['id' => 'sku-1', 'title' => 'Tent', 'price' => 10.0],
            'quantity' => 1,
        ]];

        $cart = $mapper->toCartCreateRequest(['line_items' => $lineItems, 'signals' => $signals]);
        $checkout = $mapper->toCheckoutCreateRequest(['line_items' => $lineItems, 'signals' => $signals]);

        self::assertSame($signals, $cart->signals?->toArray());
        self::assertSame($signals, $checkout->signals?->toArray());
    }

    /**
     * A payload with no signals must produce null rather than an empty Signals object, so
     * a capability can tell "the agent sent no hints" from "the agent sent an empty set".
     * A scalar where an object belongs is the same answer -- it is not signals.
     */
    #[Test]
    public function itReportsAbsentSignalsAsNullRatherThanAsAnEmptySet(): void
    {
        $mapper = new HttpPayloadMapper();
        $lineItems = [[
            'item' => ['id' => 'sku-1', 'title' => 'Tent', 'price' => 10.0],
            'quantity' => 1,
        ]];

        self::assertNull($mapper->toCartCreateRequest(['line_items' => $lineItems])->signals);
        self::assertNull($mapper->toCartCreateRequest(['line_items' => $lineItems, 'signals' => 'nonsense'])->signals);
        self::assertSame([], $mapper->toCartCreateRequest(['line_items' => $lineItems, 'signals' => []])->signals?->toArray());
    }
}
