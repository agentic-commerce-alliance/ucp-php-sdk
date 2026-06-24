<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Service\HttpRequestContextFactoryInterface;
use Ucp\Sdk\Service\IdempotencyServiceInterface;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;
use Ucp\Sdk\Symfony\UcpSdkConfiguration;

/** @internal */
final class RequestContextListener
{
    public function __construct(
        private readonly HttpRequestContextFactoryInterface $requestContextFactory,
        private readonly IdempotencyServiceInterface $idempotencyService,
        private readonly UcpResponseFactory $responseFactory,
        private readonly UcpSdkConfiguration $configuration,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (! $this->isUcpRequest($request)) {
            return;
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = implode(', ', array_map(static fn (?string $entry): string => (string) $entry, $value));
        }

        $query = $request->query->all();
        ksort($query);
        $query = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR), $query);

        $body = $request->getContent();
        if (strlen($body) > $this->configuration->maxRequestBodyBytes) {
            $event->setResponse($this->responseFactory->error(
                'Request body exceeds the maximum allowed size.',
                413,
                [['type' => 'error', 'content' => '$.body exceeds the maximum allowed size']],
            ));

            return;
        }

        $context = $this->requestContextFactory->create(new HttpRequest(
            $request->getMethod(),
            $request->getUri(),
            $headers,
            $query,
            $body,
        ));
        $request->attributes->set('ucp_request_context', $context);

        $isMutating = in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true);
        if (
            $isMutating
            && $this->requiresUcpIdempotency($request)
            && $context->runtimeConfiguration?->idempotencyRequired === true
            && $context->idempotencyKey === null
        ) {
            throw new ValidationException(
                'Idempotency key is required for mutating UCP requests.',
                ['$.headers.idempotency-key is required'],
            );
        }

        if ($context->idempotencyKey !== null && $isMutating) {
            $fingerprint = hash('sha256', implode('|', [
                $request->getMethod(),
                $request->getPathInfo(),
                http_build_query($query),
                $body,
            ]));
            $record = $this->idempotencyService->claim($context->idempotencyKey, $fingerprint);
            $request->attributes->set('ucp_idempotency_record', $record);

            if ($record->status === 'completed' && $record->statusCode !== null && ! $record->replayable) {
                throw new IdempotencyConflictException('Idempotency key refers to a completed response that is no longer replayable.');
            }

            if ($record->status === 'completed' && $record->responseBody !== null && $record->statusCode !== null) {
                $event->setResponse(new JsonResponse($record->responseBody, $record->statusCode, ['Idempotency-Replay' => '1']));
            }
        }
    }

    private function isUcpRequest(Request $request): bool
    {
        $path = $request->getPathInfo();

        if ($path === '/ucp/mcp' || str_starts_with($path, '/ucp/mcp/')) {
            return false;
        }

        return str_starts_with($path, '/ucp/');
    }

    private function requiresUcpIdempotency(Request $request): bool
    {
        return $request->getPathInfo() !== '/ucp/v1/oauth/token';
    }
}
