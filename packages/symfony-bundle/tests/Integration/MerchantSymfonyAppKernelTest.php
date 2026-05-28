<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Integration;

use Doctrine\DBAL\Connection;
use MerchantSymfonyApp\Kernel;
use PHPUnit\Framework\Attributes\Test;
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
        self::assertSame('Acme Outdoor', $profile['capabilities']['dev.ucp.shopping.checkout'][0]['config']['merchant']['brand']);

        $this->request($client, 'POST', '/ucp/v1/catalog/search', ['CONTENT_TYPE' => 'application/json'], json_encode([
            'query' => 'tent',
            'limit' => 5,
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $catalog = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $catalog['items']);
        self::assertSame('tent-4p', $catalog['items'][0]['id']);
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

        $this->request($client, 'POST', '/ucp/v1/checkout-sessions/' . $checkoutId . '/complete');

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
}
