<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Event\OrderWebhookDispatchEvent;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponseInterface;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Model\Webhook\WebhookDispatchResult;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\TenantAwareManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\EventDispatcherInterface;
use Ucp\Sdk\Service\HttpClientInterface;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;

/** @internal */
final class DefaultOrderWebhookDispatcher implements OrderWebhookPublisherInterface
{
    public const DEFAULT_MAX_RESPONSE_BODY_BYTES = 256 * 1024;

    /**
     * How many times a delivery is attempted before the business gives up on it.
     *
     * order.md requires failed deliveries to be retried. Three attempts is the whole retry
     * policy: this is a synchronous dispatcher, so anything longer holds the request that
     * triggered it. A business that needs real backoff queues the delivery and drives this
     * from a worker, which is why the result carries `retryable` rather than swallowing it.
     */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /**
     * @param iterable<OrderWebhookEnricherInterface> $enrichers
     */
    public function __construct(
        private readonly ManagedSigningKeyRepositoryInterface $signingKeyRepository,
        private readonly RequestSignatureServiceInterface $requestSignatureService,
        private readonly HttpClientInterface $httpClient,
        private readonly iterable $enrichers,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly int $timeoutSeconds = 10,
        ?UrlSafetyValidator $urlSafetyValidator = null,
        private readonly int $maxResponseBodyBytes = self::DEFAULT_MAX_RESPONSE_BODY_BYTES,
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
    ) {
        $this->urlSafetyValidator = $urlSafetyValidator ?? new UrlSafetyValidator();
    }

    private readonly UrlSafetyValidator $urlSafetyValidator;

    public function publish(string $targetUrl, OrderWebhookPayload $payload, RequestContext $context): WebhookDispatchResult
    {
        $validatedUrl = $this->urlSafetyValidator->validateAndResolve($targetUrl);

        foreach ($this->enrichers as $enricher) {
            $payload = $enricher->enrich($payload, $context);
        }

        $event = new OrderWebhookDispatchEvent($payload, $context, $targetUrl);
        $this->eventDispatcher->dispatch($event);
        $payload = $event->getPayload();

        $key = $this->resolveSigningKey($context);
        if ($key === null) {
            throw new UcpException('No signing key available for webhook dispatch.');
        }

        $body = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);

        // Standard Webhooks identity, established once for the event rather than per attempt.
        // A retry is the same event delivered again, and a receiver deduplicating on
        // `Webhook-Id` has to see the same value or it will process the order twice.
        $deliveryHeaders = [
            'Content-Type' => 'application/json',
            'Webhook-Id' => $this->deliveryId($payload),
            'Webhook-Timestamp' => (string) time(),
        ];

        $businessProfile = $this->businessProfileUri($context);
        if ($businessProfile !== null) {
            $deliveryHeaders['UCP-Agent'] = sprintf('profile="%s"', $businessProfile);
        }

        $request = new HttpRequest('POST', $targetUrl, $deliveryHeaders, [], $body);
        $headers = [...$deliveryHeaders, ...$this->requestSignatureService->sign($request, $key)];

        $result = new WebhookDispatchResult($targetUrl, 0, false, true);

        for ($attempt = 1; $attempt <= max(1, $this->maxAttempts); $attempt++) {
            try {
                $response = $this->httpClient->request('POST', $targetUrl, [
                    'headers' => $headers,
                    'body' => $body,
                    'timeout' => $this->timeoutSeconds,
                    'max_redirects' => 0,
                    'buffer' => false,
                    'resolve' => $validatedUrl->resolveMap(),
                ]);

                $result = $this->toResult($targetUrl, $response);
            } catch (\Throwable) {
                $result = new WebhookDispatchResult($targetUrl, 0, false, true);
            }

            // Retrying a delivery the receiver rejected outright -- a 4xx -- just repeats a
            // request it has already refused, so only retryable failures are attempted again.
            if ($result->successful || ! $result->retryable) {
                return $result;
            }
        }

        return $result;
    }

    /**
     * A stable identifier for this event.
     *
     * Derived from the order and the event name rather than random, so a business that
     * re-publishes the same event -- a retry after a crash, a replayed queue entry -- produces
     * the identifier the receiver already deduplicated on.
     */
    private function deliveryId(OrderWebhookPayload $payload): string
    {
        return 'msg_' . substr(hash('sha256', $payload->event . '|' . $payload->orderId), 0, 32);
    }

    /**
     * The business's own profile URL, which tells the receiver whose keys to verify with.
     */
    private function businessProfileUri(RequestContext $context): ?string
    {
        $baseUri = $context->runtimeConfiguration?->baseUri;
        if (! is_string($baseUri) || $baseUri === '') {
            return null;
        }

        return rtrim($baseUri, '/') . '/.well-known/ucp';
    }

    private function toResult(string $targetUrl, HttpResponseInterface $response): WebhookDispatchResult
    {
        $statusCode = $response->getStatusCode();
        $retryable = $statusCode === 408 || $statusCode === 425 || $statusCode === 429 || $statusCode >= 500;
        $successful = $statusCode >= 200 && $statusCode < 300;
        $headers = [];

        foreach ($response->getHeaders(false) as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return new WebhookDispatchResult(
            $targetUrl,
            $statusCode,
            $successful,
            $retryable,
            $headers,
            $this->responseBody($response),
        );
    }

    private function responseBody(HttpResponseInterface $response): ?string
    {
        $headers = $response->getHeaders(false);
        $contentLength = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : null;
        if ($contentLength !== null && $contentLength > $this->maxResponseBodyBytes) {
            $response->cancel();

            return null;
        }

        $content = '';
        foreach ($this->httpClient->stream($response, $this->timeoutSeconds) as $chunk) {
            if ($chunk->isTimeout() || $chunk->isFirst()) {
                continue;
            }

            $content .= $chunk->getContent();
            if (strlen($content) > $this->maxResponseBodyBytes) {
                $response->cancel();

                return null;
            }
        }

        return $content;
    }

    private function resolveSigningKey(RequestContext $context): ?ManagedSigningKey
    {
        $active = $this->signingKeyRepository instanceof TenantAwareManagedSigningKeyRepositoryInterface
            ? $this->signingKeyRepository->activeForTenant($context->runtimeConfiguration?->tenantIdentifier)
            : $this->signingKeyRepository->active();

        return $active[0] ?? null;
    }
}
