<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\EventListener;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

/** @internal */
final class ExceptionListener
{
    public function __construct(
        private readonly UcpResponseFactory $responseFactory,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (! $this->isUcpRequest($event->getRequest())) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($throwable instanceof ValidationException) {
            $event->setResponse($this->errorResponse($event->getRequest(), $throwable->getMessage(), 422, array_map(
                static fn (string $violation): array => ['type' => 'error', 'content' => $violation],
                $throwable->getViolations(),
            )));

            return;
        }

        if ($throwable instanceof IdempotencyConflictException) {
            $event->setResponse($this->errorResponse($event->getRequest(), $throwable->getMessage(), 409));

            return;
        }

        if ($throwable instanceof SignatureException) {
            $event->setResponse($this->errorResponse($event->getRequest(), $throwable->getMessage(), 401));

            return;
        }

        if ($throwable instanceof OAuthException) {
            $event->setResponse($this->errorResponse($event->getRequest(), $throwable->getMessage(), 400));

            return;
        }

        if ($throwable instanceof NegotiationException) {
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 400, [[
                'type' => 'error',
                'content' => $throwable->getMessage(),
                'code' => $throwable->errorCode,
            ]]));

            return;
        }

        if ($throwable instanceof UnsupportedCapabilityException) {
            $event->setResponse($this->errorResponse($event->getRequest(), $throwable->getMessage(), 501));

            return;
        }

        if ($throwable instanceof ResourceNotFoundException) {
            $event->setResponse($this->errorResponse($event->getRequest(), $throwable->getMessage(), 404));

            return;
        }

        if ($throwable instanceof ConfigurationException) {
            $event->setResponse($this->errorResponse($event->getRequest(), $throwable->getMessage(), 500));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Request failed.';
            $event->setResponse($this->errorResponse($event->getRequest(), $message, $throwable->getStatusCode()));

            return;
        }

        $event->setResponse($this->errorResponse($event->getRequest(), 'Internal server error.', 500));
    }

    private function isUcpRequest(Request $request): bool
    {
        $path = $request->getPathInfo();

        if ($path === '/ucp/mcp' || str_starts_with($path, '/ucp/mcp/')) {
            return false;
        }

        if (str_starts_with($path, '/ucp/')) {
            return true;
        }

        return in_array($path, [
            '/.well-known/ucp',
            '/.well-known/oauth-authorization-server',
            '/.well-known/openid-configuration',
            '/.well-known/agent-card.json',
        ], true);
    }

    /**
     * @param list<array<string, string>> $messages
     */
    private function errorResponse(Request $request, string $message, int $status, array $messages = []): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $context = $request->attributes->get('ucp_request_context');

        return $this->responseFactory->error(
            $message,
            $status,
            $messages,
            $context instanceof RequestContext ? $context : null,
            $this->operationFromRequest($request),
        );
    }

    private function operationFromRequest(Request $request): ?string
    {
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        return match (true) {
            $path === '/ucp/v1/catalog/search' => 'catalog.search',
            $path === '/ucp/v1/catalog/lookup' => 'catalog.lookup',
            preg_match('#^/ucp/v1/catalog/product/[^/]+$#', $path) === 1 => 'catalog.product',
            $path === '/ucp/v1/carts' && $method === 'POST' => 'cart.create',
            preg_match('#^/ucp/v1/carts/[^/]+$#', $path) === 1 && $method === 'GET' => 'cart.get',
            preg_match('#^/ucp/v1/carts/[^/]+$#', $path) === 1 && in_array($method, ['PUT', 'PATCH'], true) => 'cart.update',
            preg_match('#^/ucp/v1/carts/[^/]+/cancel$#', $path) === 1 => 'cart.cancel',
            $path === '/ucp/v1/checkout-sessions' && $method === 'POST' => 'checkout.create',
            preg_match('#^/ucp/v1/checkout-sessions/[^/]+$#', $path) === 1 && $method === 'GET' => 'checkout.get',
            preg_match('#^/ucp/v1/checkout-sessions/[^/]+$#', $path) === 1 && in_array($method, ['PUT', 'PATCH'], true) => 'checkout.update',
            preg_match('#^/ucp/v1/checkout-sessions/[^/]+/complete$#', $path) === 1 => 'checkout.complete',
            preg_match('#^/ucp/v1/checkout-sessions/[^/]+/cancel$#', $path) === 1 => 'checkout.cancel',
            $path === '/ucp/v1/orders' || preg_match('#^/ucp/v1/orders/[^/]+$#', $path) === 1 => 'order.get',
            $path === '/ucp/v1/oauth/authorize' => 'oauth.authorize',
            $path === '/ucp/v1/oauth/token' => 'oauth.token',
            $path === '/ucp/v1/tokenize' => 'tokenization',
            default => null,
        };
    }
}
