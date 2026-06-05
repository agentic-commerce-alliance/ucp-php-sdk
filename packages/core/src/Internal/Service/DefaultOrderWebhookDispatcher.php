<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Ucp\Sdk\Contract\OrderWebhookEnricherInterface;
use Ucp\Sdk\Event\OrderWebhookDispatchEvent;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Model\Webhook\OrderWebhookPayload;
use Ucp\Sdk\Model\Webhook\WebhookDispatchResult;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Service\OrderWebhookPublisherInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;

/** @internal */
final readonly class DefaultOrderWebhookDispatcher implements OrderWebhookPublisherInterface
{
    /**
     * @param iterable<OrderWebhookEnricherInterface> $enrichers
     */
    public function __construct(
        private ManagedSigningKeyRepositoryInterface $signingKeyRepository,
        private RequestSignatureServiceInterface $requestSignatureService,
        private HttpClientInterface $httpClient,
        private iterable $enrichers,
        private EventDispatcherInterface $eventDispatcher,
        private int $timeoutSeconds = 10,
    ) {
    }

    public function publish(string $targetUrl, OrderWebhookPayload $payload, RequestContext $context): WebhookDispatchResult
    {
        foreach ($this->enrichers as $enricher) {
            $payload = $enricher->enrich($payload, $context);
        }

        $event = new OrderWebhookDispatchEvent($payload, $context, $targetUrl);
        $this->eventDispatcher->dispatch($event);
        $payload = $event->getPayload();

        $key = $this->resolveSigningKey();
        if ($key === null) {
            throw new UcpException('No signing key available for webhook dispatch.');
        }

        $body = json_encode($payload->toArray(), JSON_THROW_ON_ERROR);
        $request = new HttpRequest('POST', $targetUrl, ['Content-Type' => 'application/json'], [], $body);
        $headers = $this->requestSignatureService->sign($request, $key);
        $headers['Content-Type'] = 'application/json';

        try {
            $response = $this->httpClient->request('POST', $targetUrl, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $this->timeoutSeconds,
                'max_redirects' => 0,
            ]);
        } catch (\Throwable) {
            return new WebhookDispatchResult($targetUrl, 0, false, true);
        }

        try {
            return $this->toResult($targetUrl, $response);
        } catch (\Throwable) {
            return new WebhookDispatchResult($targetUrl, 0, false, true);
        }
    }

    private function toResult(string $targetUrl, ResponseInterface $response): WebhookDispatchResult
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
            $response->getContent(false),
        );
    }

    private function resolveSigningKey(): ?\Ucp\Sdk\Model\Security\ManagedSigningKey
    {
        $active = $this->signingKeyRepository->active();
        return $active[0] ?? null;
    }
}
