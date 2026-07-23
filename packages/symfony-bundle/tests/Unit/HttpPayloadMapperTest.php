<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Ucp\Sdk\Symfony\Bridge\HttpPayloadMapper;

final class HttpPayloadMapperTest extends TestCase
{
    #[Test]
    public function itPreservesTheFullPaymentInstrumentOnCheckoutComplete(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutCompleteRequest('checkout-1', [
            'payment' => ['instruments' => [[
                'id' => 'pi_123',
                'type' => 'card',
                'handler_id' => 'com.example.psp',
                'selected' => true,
                'credential' => ['token' => 'tok_abc'],
                'billing_address' => ['street_address' => '1 Market St', 'address_country' => 'US', 'postal_code' => '94105'],
                'display' => ['brand' => 'visa', 'last4' => '4242'],
            ]]],
        ]);

        $instrument = $request->payment?->instruments[0];
        self::assertNotNull($instrument);
        self::assertSame('pi_123', $instrument->id);
        self::assertSame('card', $instrument->type);
        self::assertSame('com.example.psp', $instrument->handlerId);
        self::assertTrue($instrument->selected);
        self::assertSame(['token' => 'tok_abc'], $instrument->credential);
        $billingAddress = $instrument->billingAddress;
        self::assertNotNull($billingAddress);
        self::assertSame('1 Market St', $billingAddress->streetAddress);
        self::assertSame('US', $billingAddress->addressCountry);
        self::assertSame(['brand' => 'visa', 'last4' => '4242'], $instrument->display);
    }

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
    public function itMapsCheckoutCompletePaymentAndAp2Payload(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutCompleteRequest('checkout-1', [
            'payment' => [
                'instruments' => [[
                    'type' => 'tokenized',
                    'handler_id' => 'com.example.psp',
                    'credential' => ['token' => 'payment_mandate'],
                ]],
            ],
            'ap2' => [
                'checkout_mandate' => 'eyJhbGciOiJFUzI1NiJ9.eyJjaGVja291dCI6dHJ1ZX0.c2lnbmF0dXJl~ZGlzY2xvc3VyZQ',
            ],
        ]);

        self::assertSame('checkout-1', $request->id);
        $payment = $request->payment;
        self::assertNotNull($payment);
        self::assertSame('com.example.psp', $payment->instruments[0]->handlerId);
        self::assertSame('payment_mandate', $payment->instruments[0]->credential['token']);
        self::assertSame('eyJhbGciOiJFUzI1NiJ9.eyJjaGVja291dCI6dHJ1ZX0.c2lnbmF0dXJl~ZGlzY2xvc3VyZQ', $request->ap2?->checkoutMandate);
    }

    #[Test]
    public function itMapsCheckoutCompleteRequestsWithoutPaymentOrAp2Payload(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutCompleteRequest('checkout-1', []);

        self::assertSame('checkout-1', $request->id);
        self::assertNull($request->payment);
        self::assertNull($request->ap2);
    }

    #[Test]
    public function itIgnoresMalformedCheckoutCompleteInstrumentRows(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutCompleteRequest('checkout-1', [
            'payment' => [
                'instruments' => ['not-an-instrument', ['handler_id' => 'com.example.psp']],
            ],
        ]);

        $payment = $request->payment;
        self::assertNotNull($payment);
        self::assertCount(1, $payment->instruments);
        self::assertSame('com.example.psp', $payment->instruments[0]->handlerId);
    }

    #[Test]
    public function itMapsBareInstrumentCheckoutCompletePaymentPayloads(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutCompleteRequest('checkout-1', [
            'payment' => [
                'type' => 'tokenized',
                'handler_id' => 'com.example.psp',
                'credential' => ['token' => 'payment_mandate'],
            ],
        ]);

        $payment = $request->payment;
        self::assertNotNull($payment);
        self::assertCount(1, $payment->instruments);
        self::assertSame('com.example.psp', $payment->instruments[0]->handlerId);
        self::assertSame('payment_mandate', $payment->instruments[0]->credential['token']);
    }

    #[Test]
    public function itRejectsMalformedCheckoutMandates(): void
    {
        $mapper = new HttpPayloadMapper();

        $this->expectException(BadRequestHttpException::class);

        $mapper->toCheckoutCompleteRequest('checkout-1', [
            'ap2' => ['checkout_mandate' => ['not' => 'a-string']],
        ]);
    }

    #[Test]
    public function itRejectsEmptyCheckoutMandates(): void
    {
        $mapper = new HttpPayloadMapper();

        $this->expectException(BadRequestHttpException::class);

        $mapper->toCheckoutCompleteRequest('checkout-1', [
            'ap2' => ['checkout_mandate' => ''],
        ]);
    }

    #[Test]
    public function itRejectsCheckoutMandatesThatAreNotSdJwtFormatted(): void
    {
        $mapper = new HttpPayloadMapper();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('ap2.checkout_mandate must be an SD-JWT formatted string.');

        $mapper->toCheckoutCompleteRequest('checkout-1', [
            'ap2' => ['checkout_mandate' => 'not a mandate'],
        ]);
    }

    #[Test]
    public function itAcceptsSdJwtCheckoutMandatesWithEmptyPayloadAndDisclosureSegments(): void
    {
        $mapper = new HttpPayloadMapper();

        $request = $mapper->toCheckoutCompleteRequest('checkout-1', [
            'ap2' => ['checkout_mandate' => 'eyJhbGciOiJFUzI1NiJ9..c2lnbmF0dXJl'],
        ]);

        self::assertSame('eyJhbGciOiJFUzI1NiJ9..c2lnbmF0dXJl', $request->ap2?->checkoutMandate);
    }
}
