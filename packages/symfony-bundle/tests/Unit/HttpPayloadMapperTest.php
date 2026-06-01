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
}
