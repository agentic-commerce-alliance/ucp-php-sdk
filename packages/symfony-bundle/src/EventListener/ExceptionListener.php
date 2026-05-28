<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\EventListener;

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

final readonly class ExceptionListener
{
    public function __construct(
        private UcpResponseFactory $responseFactory,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
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

        if ($throwable instanceof UnsupportedCapabilityException) {
            $event->setResponse($this->responseFactory->error($throwable->getMessage(), 501));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Request failed.';
            $event->setResponse($this->responseFactory->error($message, $throwable->getStatusCode()));

            return;
        }

        $event->setResponse($this->responseFactory->error('Internal server error.', 500));
    }
}
