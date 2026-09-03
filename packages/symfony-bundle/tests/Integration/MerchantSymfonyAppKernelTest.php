<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantSymfonyApp\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MerchantSymfonyAppKernelTest extends WebTestCase
{
    use CreatesConfiguredKernelBrowserTrait;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    #[Test]
    public function itServesMerchantProfileAndCatalogResults(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));
        $this->request($client, 'GET', '/.well-known/ucp');

        self::assertResponseIsSuccessful();
        $profile = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Acme Outdoor', $profile['ucp']['capabilities']['dev.ucp.shopping.checkout'][0]['config']['merchant']['brand']);

        $this->request($client, 'POST', '/ucp/v1/catalog/search', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'query' => 'tent',
            'limit' => 5,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $catalog = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $catalog['products']);
        self::assertSame('tent-4p', $catalog['products'][0]['id']);
    }

    #[Test]
    public function itReturnsBadRequestForInvalidRestJsonBodies(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'POST', '/ucp/v1/catalog/search', ['CONTENT_TYPE' => 'application/json'], '{');
        self::assertResponseStatusCodeSame(400);
        $malformed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $malformed['ucp']['status']);
        self::assertSame('Malformed JSON request body.', $malformed['messages'][0]['content']);

        $this->request($client, 'POST', '/ucp/v1/catalog/search', ['CONTENT_TYPE' => 'application/json'], '123');
        self::assertResponseStatusCodeSame(400);
        $scalar = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $scalar['ucp']['status']);
        self::assertSame('JSON request body must be an object.', $scalar['messages'][0]['content']);
    }

    #[Test]
    public function itRunsMerchantCheckoutLifecycle(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'line_items' => [
                [
                    'item' => ['id' => 'tent-4p', 'title' => 'Placeholder', 'price' => 1.0],
                    'quantity' => 1,
                ],
            ],
            'buyer' => [
                'email' => 'buyer@example.test',
                'first_name' => 'Alex',
                'last_name' => 'Summit',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Acme Outdoor', $created['merchant']['brand']);
        self::assertSame('tent-4p', $created['line_items'][0]['item']['id']);

        $checkoutId = $created['id'];

        $this->request($client, 'PATCH', '/ucp/v1/checkout-sessions/' . $checkoutId, ['CONTENT_TYPE' => 'application/json'], json_encode([
            'line_items' => [
                [
                    'item' => ['id' => 'tent-4p'],
                    'quantity' => 1,
                ],
            ],
            'buyer' => [
                'email' => 'buyer@example.test',
                'first_name' => 'Alex',
                'last_name' => 'Summit',
            ],
            'payment' => [
                'type' => 'card',
                'handler_id' => 'merchant.card',
                'credential' => [
                    'card_last4' => '4242',
                ],
            ],
            'fulfillment' => [
                'type' => 'shipping',
                'method_id' => 'express-shipping',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ready_for_complete', $updated['status']);

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions/' . $checkoutId . '/complete', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'payment' => ['instruments' => []],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $completed = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('completed', $completed['status']);
        self::assertStringStartsWith('ord_', $completed['order']['id']);

        $this->request($client, 'GET', '/ucp/v1/orders/' . $completed['order']['id']);

        self::assertResponseIsSuccessful();
        $order = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($completed['order']['id'], $order['id']);
        self::assertSame('tent-4p', $order['line_items'][0]['item']['id']);
    }

    #[Test]
    public function itCreatesCheckoutFromExistingCart(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'POST', '/ucp/v1/carts', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'line_items' => [[
                'item' => ['id' => 'tent-4p', 'title' => 'Placeholder', 'price' => 1.0],
                'quantity' => 1,
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $cart = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'cart_id' => $cart['id'],
            'line_items' => [],
            'buyer' => [
                'email' => 'buyer@example.test',
                'first_name' => 'Alex',
                'last_name' => 'Summit',
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $checkout = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($cart['id'], $checkout['id']);
        self::assertSame('tent-4p', $checkout['line_items'][0]['item']['id']);
    }

    #[Test]
    public function itExposesShoppingOperationsThroughA2a(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'GET', '/.well-known/agent-card.json');
        self::assertResponseIsSuccessful();
        $agentCard = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('http://localhost:8081/ucp/a2a', $agentCard['url']);

        $search = $this->a2a($client, 'catalog.search', [
            'query' => 'tent',
            'limit' => 1,
        ]);
        self::assertSame('tent-4p', $search['products'][0]['id']);

        $product = $this->a2a($client, 'catalog.product', [
            'id' => $search['products'][0]['id'],
        ]);
        self::assertSame('tent-4p', $product['product']['id']);
        self::assertSame('Summit 4P Tent', $product['product']['title']);

        $cart = $this->a2a($client, 'cart.create', [
            'line_items' => [[
                'item' => ['id' => 'tent-4p', 'title' => 'Placeholder', 'price' => 1.0],
                'quantity' => 1,
            ]],
        ]);
        self::assertStringStartsWith('cart_', $cart['id']);

        $discountedCart = $this->a2a($client, 'discount.apply', [
            'cart_id' => $cart['id'],
            'code' => 'SAVE10',
        ]);
        self::assertSame('discount', $discountedCart['totals'][1]['type']);
        self::assertLessThan(0, $discountedCart['totals'][1]['amount']);

        $checkout = $this->a2a($client, 'checkout.create', [
            'line_items' => [[
                'item' => ['id' => 'tent-4p', 'title' => 'Placeholder', 'price' => 1.0],
                'quantity' => 1,
            ]],
            'buyer' => [
                'email' => 'buyer@example.test',
                'first_name' => 'Alex',
                'last_name' => 'Summit',
            ],
        ]);
        self::assertStringStartsWith('chk_', $checkout['id']);

        $updatedCheckout = $this->a2a($client, 'checkout.update', [
            'id' => $checkout['id'],
            'line_items' => [[
                'item' => ['id' => 'tent-4p'],
                'quantity' => 1,
            ]],
            'buyer' => [
                'email' => 'buyer@example.test',
                'first_name' => 'Alex',
                'last_name' => 'Summit',
            ],
            'fulfillment' => [
                'type' => 'shipping',
                'method_id' => 'express-shipping',
            ],
            'payment' => [
                'type' => 'card',
                'handler_id' => 'merchant.card',
                'credential' => [
                    'card_last4' => '4242',
                ],
            ],
        ]);
        self::assertSame('ready_for_complete', $updatedCheckout['status']);

        $completedCheckout = $this->a2a($client, 'checkout.complete', ['id' => $checkout['id'], 'payment' => ['instruments' => []]]);
        self::assertSame('completed', $completedCheckout['status']);
        self::assertStringStartsWith('ord_', $completedCheckout['order']['id']);

        $order = $this->a2a($client, 'order.get', ['id' => $completedCheckout['order']['id']]);
        self::assertSame($completedCheckout['order']['id'], $order['id']);

        $cancelableCheckout = $this->a2a($client, 'checkout.create', [
            'line_items' => [[
                'item' => ['id' => 'tent-4p', 'title' => 'Placeholder', 'price' => 1.0],
                'quantity' => 1,
            ]],
        ]);
        $canceledCheckout = $this->a2a($client, 'checkout.cancel', ['id' => $cancelableCheckout['id']]);
        self::assertSame('canceled', $canceledCheckout['status']);

        $canceledCart = $this->a2a($client, 'cart.cancel', ['id' => $cart['id']]);
        self::assertSame($cart['id'], $canceledCart['id']);
    }

    #[Test]
    public function itServesAgentCardDiscoveryUnderStrictSignaturePolicy(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(
            ['UCP_SIGNATURE_POLICY' => 'strict'],
            $this->clearMerchantState(...),
        );

        $this->request($client, 'GET', '/.well-known/agent-card.json');

        self::assertResponseIsSuccessful();
        $agentCard = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('http://localhost:8081/ucp/a2a', $agentCard['url']);
        self::assertSame('2026-08-25', $agentCard['version']);
    }

    #[Test]
    public function itRejectsInvalidA2aRequestsAndUntrustedEmbeddedOrigins(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'POST', '/ucp/a2a', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'cart.get',
            'params' => [],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
        $a2aError = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(-32602, $a2aError['error']['code']);

        $this->request($client, 'GET', '/ucp/embedded/cart/cart-demo', ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);

        $this->request($client, 'GET', '/ucp/embedded/cart/cart-demo', ['HTTP_ORIGIN' => 'https://localhost:8081']);
        self::assertResponseStatusCodeSame(403);

        $this->request($client, 'GET', '/ucp/embedded/cart/cart-demo', ['HTTP_ORIGIN' => 'http://localhost:8081/path']);
        self::assertResponseStatusCodeSame(403);

        $this->request($client, 'GET', '/ucp/embedded/cart/cart-demo', ['HTTP_ORIGIN' => 'http://localhost:8081?x=1']);
        self::assertResponseStatusCodeSame(403);

        $this->request($client, 'GET', '/ucp/embedded/cart/cart-demo', ['HTTP_ORIGIN' => 'http://localhost:8081']);
        self::assertResponseIsSuccessful();
        self::assertSame('http://localhost:8081', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertSame("frame-ancestors 'self' http://localhost:8081", $client->getResponse()->headers->get('Content-Security-Policy'));

        $this->request($client, 'OPTIONS', '/ucp/embedded/cart/cart-demo', ['HTTP_ORIGIN' => 'http://localhost:8081']);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('GET, OPTIONS', $client->getResponse()->headers->get('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Accept', $client->getResponse()->headers->get('Access-Control-Allow-Headers'));
        self::assertSame('http://localhost:8081', $client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function itRunsMerchantOauthAndWebhookInboxFlows(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'GET', '/ucp/v1/oauth/authorize?client_id=demo-client&redirect_uri=https%3A%2F%2Fplatform.example.test%2Fcallback&scope=profile&state=state-123&code_challenge=challenge-1&code_challenge_method=S256');

        self::assertResponseIsSuccessful();
        $authorization = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('code', $authorization);

        $this->request($client, 'POST', '/ucp/v1/oauth/token', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'grant_type' => 'authorization_code',
            'code' => $authorization['code'],
            'client_id' => 'demo-client',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $token = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('access_token', $token);

        $this->request($client, 'POST', '/merchant/demo/webhook-inbox', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'event' => 'order.created',
            'order_id' => 'ord_demo',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(202);

        $this->request($client, 'GET', '/merchant/demo/webhook-inbox');

        self::assertResponseIsSuccessful();
        $inbox = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $inbox['entries']);
        self::assertSame('order.created', $inbox['entries'][0]['payload']['event']);
    }

    #[Test]
    public function itRejectsReplayedAndExpiredMerchantOauthCodes(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'GET', '/ucp/v1/oauth/authorize?client_id=demo-client&redirect_uri=https%3A%2F%2Fplatform.example.test%2Fcallback&scope=profile&state=state-123&code_challenge=challenge-1&code_challenge_method=S256');
        self::assertResponseIsSuccessful();

        $authorization = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $tokenPayload = json_encode([
            'grant_type' => 'authorization_code',
            'code' => $authorization['code'],
            'client_id' => 'demo-client',
        ], JSON_THROW_ON_ERROR);

        $this->request($client, 'POST', '/ucp/v1/oauth/token', ['CONTENT_TYPE' => 'application/json'], $tokenPayload);
        self::assertResponseIsSuccessful();

        $this->request($client, 'POST', '/ucp/v1/oauth/token', ['CONTENT_TYPE' => 'application/json'], $tokenPayload);
        self::assertResponseStatusCodeSame(400);

        $this->request($client, 'GET', '/ucp/v1/oauth/authorize?client_id=demo-client&redirect_uri=https%3A%2F%2Fplatform.example.test%2Fcallback&scope=profile&state=state-456&code_challenge=challenge-2&code_challenge_method=S256');
        self::assertResponseIsSuccessful();
        $expiredAuthorization = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        /** @var Connection $connection */
        $connection = self::getContainer()->get('ucp_sdk.connection');
        $connection->executeStatement(
            'UPDATE ucp_oauth_state SET expires_at = :expires_at WHERE code_hash = :code_hash',
            [
                'expires_at' => time() - 5,
                'code_hash' => hash('sha256', (string) $expiredAuthorization['code']),
            ],
        );

        $this->request($client, 'POST', '/ucp/v1/oauth/token', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'grant_type' => 'authorization_code',
            'code' => $expiredAuthorization['code'],
            'client_id' => 'demo-client',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }

    private function clearMerchantState(): void
    {
        foreach (glob(dirname(__DIR__, 4) . '/examples/merchant-symfony-app/var/state/*.json') ?: [] as $file) {
            @unlink($file);
        }

        @unlink(dirname(__DIR__, 4) . '/examples/merchant-symfony-app/var/ucp_sdk.sqlite');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function a2a(KernelBrowser $client, string $method, array $params): array
    {
        $this->request($client, 'POST', '/ucp/a2a', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $response = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(42, $response['id']);
        self::assertIsArray($response['result']);

        return $response['result'];
    }
}
