<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
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
}
