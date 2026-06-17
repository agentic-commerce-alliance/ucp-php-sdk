<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use MerchantSymfonyApp\Controller\WebhookDemoController;
use MerchantSymfonyApp\Support\JsonStateStore;
use MerchantSymfonyApp\Support\MerchantSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Model\Webhook\WebhookDispatchResult;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;

final class WebhookDemoControllerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/ucp-webhook-demo-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    #[Test]
    public function itDispatchesExplicitWebhookPayload(): void
    {
        $context = new RequestContext('merchant.example');
        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'https://receiver.example/webhook',
                self::callback(static fn (OrderWebhookPayload $payload): bool => $payload->event === 'order.paid'
                    && $payload->orderId === 'order-42'
                    && $payload->payload === ['source' => 'test']),
                $context,
            )
            ->willReturn(new WebhookDispatchResult('https://receiver.example/webhook', 204, true));

        $controller = new WebhookDemoController($publisher, $this->stateStore(), $this->settings());
        $request = $this->jsonRequest([
            'target_url' => 'https://receiver.example/webhook',
            'order_id' => 'order-42',
            'event' => 'order.paid',
            'payload' => ['source' => 'test'],
        ], $context);

        $response = $controller->dispatch($request);
        $payload = $this->decode($response);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('queued', $payload['status']);
        self::assertSame('https://receiver.example/webhook', $payload['target_url']);
        self::assertSame('order-42', $payload['order_id']);
        self::assertSame('order.paid', $payload['event']);
        self::assertSame(204, $payload['delivery_status']);
        self::assertTrue($payload['successful']);
    }

    #[Test]
    public function itDispatchesWebhookPayloadWithDefaults(): void
    {
        $context = new RequestContext('merchant.example');
        $publisher = $this->createMock(OrderWebhookPublisherInterface::class);
        $publisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'https://default.example/webhook',
                self::callback(static fn (OrderWebhookPayload $payload): bool => $payload->event === 'order.created'
                    && $payload->orderId === 'order-demo-1001'
                    && $payload->payload === ['source' => 'merchant-symfony-app']),
                $context,
            )
            ->willReturn(new WebhookDispatchResult('https://default.example/webhook', 202, true));

        $controller = new WebhookDemoController($publisher, $this->stateStore(), $this->settings());
        $response = $controller->dispatch($this->jsonRequest([], $context, emptyBody: true));
        $payload = $this->decode($response);

        self::assertSame('https://default.example/webhook', $payload['target_url']);
        self::assertSame('order-demo-1001', $payload['order_id']);
        self::assertSame('order.created', $payload['event']);
        self::assertSame(202, $payload['delivery_status']);
    }

    #[Test]
    public function itStoresReceivedWebhookEntriesAndReturnsInbox(): void
    {
        $controller = new WebhookDemoController(
            $this->createMock(OrderWebhookPublisherInterface::class),
            $this->stateStore(),
            $this->settings(),
        );

        $receiveResponse = $controller->receive(Request::create(
            '/merchant/demo/webhook-inbox',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_ID' => 'delivery-1'],
            json_encode(['event' => 'order.created', 'order_id' => 'ord_1'], JSON_THROW_ON_ERROR),
        ));

        self::assertSame(202, $receiveResponse->getStatusCode());
        self::assertSame(['received' => true], $this->decode($receiveResponse));

        $inbox = $this->decode($controller->inbox());

        self::assertCount(1, $inbox['entries']);
        self::assertSame('delivery-1', $inbox['entries'][0]['headers']['x-webhook-id'][0]);
        self::assertSame('order.created', $inbox['entries'][0]['payload']['event']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload, RequestContext $context, bool $emptyBody = false): Request
    {
        $request = Request::create(
            '/merchant/demo/order-webhooks/dispatch',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $emptyBody ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('ucp_request_context', $context);

        return $request;
    }

    private function stateStore(): JsonStateStore
    {
        return new JsonStateStore($this->projectDir);
    }

    private function settings(): MerchantSettings
    {
        return new MerchantSettings(
            'https://merchant.example',
            'Acme Outdoor',
            'EUR',
            'DE',
            'https://default.example/webhook',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
