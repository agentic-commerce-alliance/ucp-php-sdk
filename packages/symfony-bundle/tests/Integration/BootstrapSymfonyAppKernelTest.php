<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use BootstrapSymfonyApp\Kernel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BootstrapSymfonyAppKernelTest extends WebTestCase
{
    use CreatesConfiguredKernelBrowserTrait;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    #[Test]
    public function itServesTheDiscoveryProfile(): void
    {
        $client = $this->createConfiguredClient();
        $this->request($client, 'GET', '/.well-known/ucp');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-04-08', $payload['ucp']['version']);
        self::assertArrayHasKey('dev.ucp.shopping.checkout', $payload['ucp']['capabilities']);
        self::assertArrayHasKey('dev.ucp.shopping.order', $payload['ucp']['capabilities']);
        self::assertArrayHasKey('com.demo.tokenizer', $payload['ucp']['payment_handlers']);
        self::assertArrayNotHasKey('supported_versions', $payload['ucp']);
        self::assertArrayNotHasKey('capabilities', $payload);
        self::assertArrayNotHasKey('payment_handlers', $payload);
        self::assertNotEmpty($payload['keys']);
    }

    #[Test]
    public function itServesCatalogAndCheckoutRoutes(): void
    {
        $client = $this->createConfiguredClient();
        $this->request($client, 'POST', '/ucp/v1/catalog/search', ['CONTENT_TYPE' => 'application/json'], json_encode(['query' => 'demo'], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'line_items' => [
                [
                    'item' => ['id' => 'sku-1', 'title' => 'Demo Product', 'price' => 19.99],
                    'quantity' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('demo', $payload['extension']['source']);
    }

    #[Test]
    public function itReplaysCompletedIdempotentResponses(): void
    {
        $client = $this->createConfiguredClient();
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'idempotent-1',
        ];
        $content = json_encode([
            'line_items' => [
                [
                    'item' => ['id' => 'sku-1', 'title' => 'Demo Product', 'price' => 19.99],
                    'quantity' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions', $server, $content);
        self::assertResponseStatusCodeSame(201);

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions', $server, $content);
        self::assertResponseIsSuccessful();
        self::assertSame('1', $client->getResponse()->headers->get('Idempotency-Replay'));
    }

    #[Test]
    public function itReturnsConflictWhenAnIdempotentResponseIsNoLongerReplayable(): void
    {
        $client = $this->createConfiguredClient();
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'idempotent-2',
        ];
        $content = json_encode([
            'line_items' => [
                [
                    'item' => ['id' => 'sku-1', 'title' => 'Demo Product', 'price' => 19.99],
                    'quantity' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions', $server, $content);
        self::assertResponseStatusCodeSame(201);

        /** @var Connection $connection */
        $connection = self::getContainer()->get('ucp_sdk.connection');
        $connection->update('ucp_idempotency', [
            'response_body' => null,
            'replayable' => 0,
        ], [
            'idempotency_key' => 'idempotent-2',
        ]);

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions', $server, $content);

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $payload['ucp']['status']);
    }

    #[Test]
    public function itRejectsOversizedRequestBodiesBeforeContextCreation(): void
    {
        $client = $this->createConfiguredClient();
        $this->request(
            $client,
            'POST',
            '/ucp/v1/checkout-sessions',
            ['CONTENT_TYPE' => 'application/json'],
            str_repeat('x', 300000),
        );

        self::assertResponseStatusCodeSame(413);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $payload['ucp']['status']);
    }

    #[Test]
    public function itRejectsUnsignedRequestsUnderStrictSignaturePolicy(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(['UCP_SIGNATURE_POLICY' => 'strict']);
        $this->request(
            $client,
            'POST',
            '/ucp/v1/catalog/search',
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => 'demo'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $payload['ucp']['status']);
    }

    #[Test]
    public function itServesPublicDiscoveryEndpointsUnderStrictSignaturePolicy(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(['UCP_SIGNATURE_POLICY' => 'strict']);

        $this->request($client, 'GET', '/.well-known/ucp');
        self::assertResponseIsSuccessful();
        $profile = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-04-08', $profile['ucp']['version']);

        $this->request($client, 'GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        $metadata = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('issuer', $metadata);
        self::assertArrayHasKey('token_endpoint', $metadata);
    }

    #[Test]
    public function itDoesNotRequireSignaturesForDisabledAgentCardDiscovery(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(['UCP_SIGNATURE_POLICY' => 'strict']);

        $this->request($client, 'GET', '/.well-known/agent-card.json');

        self::assertResponseStatusCodeSame(404);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $payload['ucp']['status']);
        self::assertSame('A2A transport is not enabled.', $payload['messages'][0]['content']);
    }

    #[Test]
    public function itServesOAuthMetadata(): void
    {
        $client = $this->createConfiguredClient();
        $this->request($client, 'GET', '/.well-known/oauth-authorization-server');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('issuer', $payload);
        self::assertArrayHasKey('token_endpoint', $payload);
    }

    #[Test]
    public function itDoesNotExposeNonRestTransportsByDefault(): void
    {
        $client = $this->createConfiguredClient();

        $this->request($client, 'GET', '/.well-known/agent-card.json');
        self::assertResponseStatusCodeSame(404);

        $this->request($client, 'GET', '/ucp/embedded/cart/demo');
        self::assertResponseStatusCodeSame(404);
    }
}
