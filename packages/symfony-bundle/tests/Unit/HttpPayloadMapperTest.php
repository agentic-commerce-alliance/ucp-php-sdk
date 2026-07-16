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
                'checkout_mandate' => 'checkout_mandate',
            ],
        ]);

        self::assertSame('checkout-1', $request->id);
        $payment = $request->payment;
        self::assertNotNull($payment);
        self::assertSame('com.example.psp', $payment->instruments[0]->handlerId);
        self::assertSame('payment_mandate', $payment->instruments[0]->credential['token']);
        self::assertSame('checkout_mandate', $request->ap2?->checkoutMandate);
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
}
