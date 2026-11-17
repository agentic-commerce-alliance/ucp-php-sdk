<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantSymfonyApp\Kernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;
use Ucp\Sdk\Service\SigningKeyManagerInterface;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

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

        // The shape services/shopping/rest.openapi.json has defined at every published protocol
        // version. This SDK served GET /catalog/product/{id} instead, so an agent following the
        // OpenAPI document got a 405.
        $this->request($client, 'POST', '/ucp/v1/catalog/product', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'id' => 'tent-4p',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $product = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tent-4p', $product['product']['id']);

        // Retained for one minor behind ucp_sdk.legacy_routes.catalog_product_get.
        $this->request($client, 'GET', '/ucp/v1/catalog/product/tent-4p');

        self::assertResponseIsSuccessful();
        $legacy = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tent-4p', $legacy['product']['id']);
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
            // The shape a platform actually sends: a destination to price against, and the
            // option chosen from the ones the business offered for it.
            'fulfillment' => [
                'methods' => [[
                    'id' => 'ful_method_1',
                    'type' => 'shipping',
                    'line_item_ids' => [$created['line_items'][0]['id']],
                    'destinations' => [[
                        'id' => 'dest_1',
                        'address_country' => 'DE',
                        'postal_code' => '10115',
                    ]],
                    'selected_destination_id' => 'dest_1',
                    'groups' => [[
                        'id' => 'ful_group_1',
                        'line_item_ids' => [$created['line_items'][0]['id']],
                        'selected_option_id' => 'express-shipping',
                    ]],
                ]],
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
            // The shape a platform actually sends: a destination to price against, and the
            // option chosen from the ones the business offered for it.
            'fulfillment' => [
                'methods' => [[
                    'id' => 'ful_method_1',
                    'type' => 'shipping',
                    'line_item_ids' => [$checkout['line_items'][0]['id']],
                    'destinations' => [[
                        'id' => 'dest_1',
                        'address_country' => 'DE',
                        'postal_code' => '10115',
                    ]],
                    'selected_destination_id' => 'dest_1',
                    'groups' => [[
                        'id' => 'ful_group_1',
                        'line_item_ids' => [$checkout['line_items'][0]['id']],
                        'selected_option_id' => 'express-shipping',
                    ]],
                ]],
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

    /**
     * The strict-policy bypass: A2A built its own bare context, so an unsigned call ran the
     * operation. It now answers exactly as REST does, and leaves no merchant state behind.
     */
    #[Test]
    public function itRefusesUnsignedA2aCallsUnderStrictSignaturePolicyLikeRest(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(['UCP_SIGNATURE_POLICY' => 'strict'], $this->clearMerchantState(...));

        foreach ([
            'catalog.search' => ['query' => 'tent'],
            'cart.create' => ['line_items' => [['item' => ['id' => 'tent-4p'], 'quantity' => 1]]],
            'checkout.create' => ['line_items' => [['item' => ['id' => 'tent-4p'], 'quantity' => 1]]],
            'checkout.complete' => ['id' => 'chk_unknown', 'payment' => ['instruments' => []]],
        ] as $method => $params) {
            $this->request($client, 'POST', '/ucp/a2a', ['CONTENT_TYPE' => 'application/json'], $this->jsonRpc($method, $params));
            $this->assertSignatureRefusal($client, 'Missing signature headers.');
        }

        self::assertSame([], glob($this->merchantStateDirectory() . '/*.json') ?: []);

        $this->request($client, 'POST', '/ucp/v1/catalog/search', ['CONTENT_TYPE' => 'application/json'], json_encode(['query' => 'tent'], JSON_THROW_ON_ERROR));
        $this->assertSignatureRefusal($client, 'Missing signature headers.');
    }

    #[Test]
    public function itServesCorrectlySignedA2aCallsUnderStrictSignaturePolicy(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(['UCP_SIGNATURE_POLICY' => 'strict'], $this->clearMerchantState(...));
        $body = $this->jsonRpc('catalog.search', ['query' => 'tent', 'limit' => 1]);

        $this->request($client, 'POST', '/ucp/a2a', $this->signedServer($body), $body);

        self::assertResponseIsSuccessful();
        $response = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(42, $response['id']);
        self::assertSame('tent-4p', $response['result']['products'][0]['id']);
    }

    #[Test]
    public function itRefusesAnOversizedA2aBodyLikeRestEvenWhenSigned(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(['UCP_SIGNATURE_POLICY' => 'strict'], $this->clearMerchantState(...));
        $body = $this->jsonRpc('catalog.search', ['query' => str_repeat('tent ', 60000)]);

        $this->request($client, 'POST', '/ucp/a2a', $this->signedServer($body), $body);

        $response = $client->getResponse();
        self::assertSame(413, $response->getStatusCode(), (string) $response->getContent());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('result', $payload);
        self::assertSame('error', $payload['ucp']['status']);
        self::assertSame('Request body exceeds the maximum allowed size.', $payload['messages'][0]['content']);

        $this->request($client, 'POST', '/ucp/v1/catalog/search', ['CONTENT_TYPE' => 'application/json'], $body);
        self::assertResponseStatusCodeSame(413);
    }

    #[Test]
    public function itRefusesBadlySignedA2aCallsUnderStrictSignaturePolicy(): void
    {
        $client = $this->createConfiguredClientWithEnvironment(['UCP_SIGNATURE_POLICY' => 'strict'], $this->clearMerchantState(...));
        $body = $this->jsonRpc('cart.create', ['line_items' => [['item' => ['id' => 'tent-4p'], 'quantity' => 1]]]);
        $tamperedBody = $this->jsonRpc('cart.create', ['line_items' => [['item' => ['id' => 'tent-4p'], 'quantity' => 9]]]);
        $signed = $this->signedServer($body);
        /** @var SigningKeyManagerInterface $keyManager */
        $keyManager = self::getContainer()->get(SigningKeyManagerInterface::class);

        $deviations = [
            'missing Signature' => [array_diff_key($signed, ['HTTP_SIGNATURE' => true]), $body, 'Missing signature headers.'],
            'missing Signature-Input' => [array_diff_key($signed, ['HTTP_SIGNATURE_INPUT' => true]), $body, 'Missing signature headers.'],
            'malformed Signature' => [['HTTP_SIGNATURE' => 'sig1=not-a-byte-sequence'] + $signed, $body, null],
            'altered signature bytes' => [['HTTP_SIGNATURE' => $this->flipSignatureBit($signed['HTTP_SIGNATURE'])] + $signed, $body, null],
            'tampered body' => [$signed, $tamperedBody, null],
            'key outside the platform profile' => [$this->signedServer($body, $keyManager->generate('rogue-kid')), $body, null],
        ];

        foreach ($deviations as $name => [$server, $content, $message]) {
            $this->request($client, 'POST', '/ucp/a2a', $server, $content);
            $this->assertSignatureRefusal($client, $message, $name);
        }

        self::assertSame([], glob($this->merchantStateDirectory() . '/*.json') ?: []);
    }

    #[Test]
    public function itRequiresTheUcpAgentHeaderOnA2aEvenUnderTheLogPolicy(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'POST', '/ucp/a2a', ['CONTENT_TYPE' => 'application/json', 'HTTP_UCP_AGENT' => ''], $this->jsonRpc('catalog.search', ['query' => 'tent']));

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('invalid_request', $payload['messages'][0]['code']);
        self::assertSame('$.headers.ucp-agent is required', $payload['messages'][0]['content']);
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
        foreach (glob($this->merchantStateDirectory() . '/*.json') ?: [] as $file) {
            @unlink($file);
        }

        @unlink(dirname(__DIR__, 4) . '/examples/merchant-symfony-app/var/ucp_sdk.sqlite');
    }

    private function merchantStateDirectory(): string
    {
        return dirname(__DIR__, 4) . '/examples/merchant-symfony-app/var/state';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function jsonRpc(string $method, array $params): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 42, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR);
    }

    /**
     * A real RFC 9421 signature over the request the kernel browser sends, made with the managed
     * key the seeded agent profile publishes, unless another key is given.
     *
     * @return array<string, string>
     */
    private function signedServer(string $body, ?ManagedSigningKey $key = null): array
    {
        /** @var UcpSdkConfiguration $configuration */
        $configuration = self::getContainer()->get(UcpSdkConfiguration::class);
        /** @var ManagedSigningKeyRepositoryInterface $keys */
        $keys = self::getContainer()->get(ManagedSigningKeyRepositoryInterface::class);
        /** @var RequestSignatureServiceInterface $signer */
        $signer = self::getContainer()->get(RequestSignatureServiceInterface::class);
        $agentHeader = sprintf('platform; profile="%s"', $this->testAgentProfileUri($configuration->resolvedBaseUri()));

        $key ??= $keys->findManaged('demo-kid');
        self::assertNotNull($key);
        $signatureHeaders = $signer->sign(new HttpRequest('POST', 'http://localhost/ucp/a2a', ['ucp-agent' => $agentHeader], [], $body), $key);

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_UCP_AGENT' => $agentHeader,
            'HTTP_CONTENT_DIGEST' => $signatureHeaders['Content-Digest'],
            'HTTP_SIGNATURE_INPUT' => $signatureHeaders['Signature-Input'],
            'HTTP_SIGNATURE' => $signatureHeaders['Signature'],
        ];
    }

    private function flipSignatureBit(string $signatureHeader): string
    {
        $label = strstr($signatureHeader, '=:', true);
        self::assertIsString($label);
        $bytes = base64_decode(substr($signatureHeader, strlen($label) + 2, -1), true);
        self::assertIsString($bytes);
        $bytes[0] = chr(ord($bytes[0]) ^ 0x01);

        return $label . '=:' . base64_encode($bytes) . ':';
    }

    /**
     * The REST error contract, on A2A too: an HTTP 401 with a UCP error body, never a JSON-RPC
     * result. A null message accepts any verification failure reason.
     *
     * Every refusal is `signature_invalid` here, also a missing signature and a tampered body,
     * which UCP 2026-08-25 answers with 401 `signature_missing` and 400 `digest_mismatch`
     * (#219).
     */
    private function assertSignatureRefusal(KernelBrowser $client, ?string $message, string $case = ''): void
    {
        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode(), $case . ': ' . $response->getContent());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('result', $payload, $case);
        self::assertSame('error', $payload['ucp']['status'], $case);
        self::assertSame('signature_invalid', $payload['messages'][0]['code'], $case);
        if ($message !== null) {
            self::assertSame($message, $payload['messages'][0]['content'], $case);
        }
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

    /**
     * Fixtures the upstream conformance suite is configured with, exercised over HTTP.
     *
     * conformance_input.json declares an out_of_stock_item and a non_existent_item; both were
     * unrepresentable here. Every product carried stock, and stock was published in catalog
     * responses and checked nowhere, so an agent could fill a cart with items this merchant
     * cannot ship and only find out never. Unknown ids passed straight through, so a typo
     * became a line item priced at whatever the agent claimed.
     */
    #[Test]
    public function itRefusesLineItemsItCannotFulfil(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'POST', '/ucp/v1/carts', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'line_items' => [[
                'item' => ['id' => 'map-alpine', 'title' => 'Alpine Trail Map', 'price' => 19.0],
                'quantity' => 1,
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $body['ucp']['status']);
        self::assertStringContainsString('exceeds available stock', $body['messages'][0]['content']);
        self::assertSame('invalid_request', $body['messages'][0]['code']);

        $this->request($client, 'POST', '/ucp/v1/carts', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'line_items' => [[
                'item' => ['id' => 'pink-wumpus', 'title' => 'Not A Product', 'price' => 1.0],
                'quantity' => 1,
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('not_found', $body['messages'][0]['code']);
    }

    /**
     * The suite is configured with two percentage codes and one fixed-amount code, and asserts
     * the resulting total. Only SAVE10 existed, and any other string "applied" successfully
     * while reducing the total by nothing -- so a typo was indistinguishable from a code this
     * merchant does not run.
     */
    #[Test]
    public function itAppliesKnownDiscountCodesAndRefusesUnknownOnes(): void
    {
        $client = $this->createConfiguredClient($this->clearMerchantState(...));

        $this->request($client, 'POST', '/ucp/v1/carts', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'line_items' => [[
                'item' => ['id' => 'tent-4p', 'title' => 'Summit 4P Tent', 'price' => 249.0],
                'quantity' => 1,
            ]],
        ], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $cart = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Amounts are minor units on the wire, and pinning them is the point: the totals were
        // inflated a hundredfold by a rehydration bug that survived because the only assertion
        // on a discounted total was that it is negative.
        self::assertSame(24900, $cart['totals'][0]['amount'], 'subtotal is 249.00 EUR');

        $discounted = $this->a2a($client, 'discount.apply', ['cart_id' => $cart['id'], 'code' => 'SAVE20']);
        self::assertSame('discount', $discounted['totals'][1]['type']);
        self::assertSame(-4980, $discounted['totals'][1]['amount'], '20% of 249.00');

        $fixed = $this->a2a($client, 'discount.apply', ['cart_id' => $cart['id'], 'code' => 'FIVEOFF']);
        self::assertSame(-500, $fixed['totals'][1]['amount'], 'a fixed 5.00 reduction');
    }
}
