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
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 422, array_map(
                static fn (string $violation): array => ['type' => 'error', 'content' => $violation],
                $throwable->getViolations(),
            )));

            return;
        }

        if ($throwable instanceof IdempotencyConflictException) {
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 409));

            return;
        }

        if ($throwable instanceof SignatureException) {
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 401));

            return;
        }

        if ($throwable instanceof OAuthException) {
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 400));

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
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 501));

            return;
        }

        if ($throwable instanceof ResourceNotFoundException) {
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 404));

            return;
        }

        if ($throwable instanceof ConfigurationException) {
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 500));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Request failed.';
            $event->setResponse($this->responseFactory->error($message, $throwable->getStatusCode()));

            return;
        }

        $event->setResponse($this->responseFactory->error('Internal server error.', 500));
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
}
