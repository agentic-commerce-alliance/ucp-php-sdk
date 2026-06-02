<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Internal\Service\DefaultOrderWebhookDispatcher;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;

final class DefaultOrderWebhookDispatcherTest extends TestCase
{
    #[Test]
    public function itPublishesSignedWebhooksAndMapsTheResponse(): void
    {
        $state = new WebhookDispatcherState();
        $activeKey = new ManagedSigningKey('active', 'public', 'private');
        $dispatcher = new DefaultOrderWebhookDispatcher(
            new class ($activeKey) implements ManagedSigningKeyRepositoryInterface {
                public function __construct(private readonly ManagedSigningKey $activeKey)
                {
                }

                public function saveManaged(ManagedSigningKey $key): void
                {
                }

                public function findManaged(string $kid): ?ManagedSigningKey
                {
                    return $kid === $this->activeKey->kid ? $this->activeKey : null;
                }

                public function deleteManaged(string $kid): bool
                {
                    return false;
                }

                public function allManaged(): array
                {
                    return [$this->activeKey];
                }

                public function active(): array
                {
                    return [$this->activeKey];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                }
            },
            new class ($state) implements RequestSignatureServiceInterface {
                public function __construct(private readonly WebhookDispatcherState $state)
                {
                }

                public function sign(HttpRequest $request, ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    $this->state->capturedRequest = $request;
                    $this->state->capturedKey = $key;

                    return ['Signature' => 'signed'];
                }

                public function verify(HttpRequest $request, array $keys): \Ucp\Sdk\Model\Security\SignatureVerificationResult
                {
                    throw new \RuntimeException('Not used in this test.');
                }
            },
            new MockHttpClient(static fn (string $method, string $url, array $options): MockResponse => new MockResponse('accepted', [
                'http_code' => 202,
                'response_headers' => ['X-Webhook-Id: demo-1'],
            ])),
            [
                new class () implements OrderWebhookEnricherInterface {
                    public function enrich(OrderWebhookPayload $payload, RequestContext $context): OrderWebhookPayload
                    {
                        return new OrderWebhookPayload($payload->event, $payload->orderId, $payload->payload + ['enriched' => true]);
                    }
                },
            ],
            new EventDispatcher(),
        );

        $result = $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-1', ['source' => 'sdk']),
            new RequestContext('merchant.example'),
        );

        self::assertFalse($result->retryable);
        self::assertTrue($result->successful);
        self::assertSame(202, $result->statusCode);
        self::assertSame('accepted', $result->responseBody);
        self::assertSame('demo-1', $result->responseHeaders['x-webhook-id']);
        self::assertInstanceOf(HttpRequest::class, $state->capturedRequest);
        self::assertNotNull($state->capturedKey);
        self::assertSame('active', $state->capturedKey->kid);
        self::assertStringContainsString('"enriched":true', $state->capturedRequest->body);
    }

    #[Test]
    public function itReturnsARetryableResultOnTransportFailure(): void
    {
        $dispatcher = new DefaultOrderWebhookDispatcher(
            new class () implements ManagedSigningKeyRepositoryInterface {
                public function saveManaged(ManagedSigningKey $key): void
                {
                }

                public function findManaged(string $kid): ?ManagedSigningKey
                {
                    return null;
                }

                public function deleteManaged(string $kid): bool
                {
                    return false;
                }

                public function allManaged(): array
                {
                    return [new ManagedSigningKey('fallback', 'public', 'private')];
                }

                public function active(): array
                {
                    return [new ManagedSigningKey('fallback', 'public', 'private')];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                }
            },
            new class () implements RequestSignatureServiceInterface {
                public function sign(HttpRequest $request, ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    return ['Signature' => 'signed'];
                }

                public function verify(HttpRequest $request, array $keys): \Ucp\Sdk\Model\Security\SignatureVerificationResult
                {
                    throw new \RuntimeException('Not used in this test.');
                }
            },
            new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'network down'])),
            [],
            new EventDispatcher(),
        );

        $result = $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-3'),
            new RequestContext('merchant.example'),
        );

        self::assertSame(0, $result->statusCode);
        self::assertFalse($result->successful);
        self::assertTrue($result->retryable);
    }

    #[Test]
    public function itThrowsWhenNoSigningKeyIsAvailable(): void
    {
        $dispatcher = new DefaultOrderWebhookDispatcher(
            new class () implements ManagedSigningKeyRepositoryInterface {
                public function saveManaged(ManagedSigningKey $key): void
                {
                }

                public function findManaged(string $kid): ?ManagedSigningKey
                {
                    return null;
                }

                public function deleteManaged(string $kid): bool
                {
                    return false;
                }

                public function allManaged(): array
                {
                    return [];
                }

                public function active(): array
                {
                    return [];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                }
            },
            new class () implements RequestSignatureServiceInterface {
                public function sign(HttpRequest $request, ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
                {
                    return [];
                }

                public function verify(HttpRequest $request, array $keys): \Ucp\Sdk\Model\Security\SignatureVerificationResult
                {
                    throw new \RuntimeException('Not used in this test.');
                }
            },
            new MockHttpClient(),
            [],
            new EventDispatcher(),
        );

        $this->expectException(UcpException::class);
        $this->expectExceptionMessage('No signing key available for webhook dispatch.');

        $dispatcher->publish(
            'https://platform.example/webhooks/orders',
            new OrderWebhookPayload('order.created', 'order-4'),
            new RequestContext('merchant.example'),
        );
    }
}

final class WebhookDispatcherState
{
    public ?HttpRequest $capturedRequest = null;

    public ?ManagedSigningKey $capturedKey = null;
}
