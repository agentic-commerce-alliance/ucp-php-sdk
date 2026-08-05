<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Model\Common\UcpErrorDescriptor;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Symfony\Bridge\UcpResponseFactory;

/** @internal */
final class ExceptionListener
{
    public function __construct(
        private readonly UcpResponseFactory $responseFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (! $this->isUcpRequest($event->getRequest())) {
            return;
        }

        $throwable = $event->getThrowable();

        // Symfony's own HTTP exceptions carry the status on the exception, which no
        // transport-agnostic descriptor can know, so they keep their own branch. Nothing
        // else needs one: UcpErrorDescriptor holds the status, code and severity for every
        // exception the SDK defines, and other transports read that same mapping instead
        // of inventing a second one.
        if (! $throwable instanceof UcpException && $throwable instanceof HttpExceptionInterface) {
            $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Request failed.';
            $event->setResponse($this->errorResponse($event->getRequest(), $message, $throwable->getStatusCode(), [
                UcpErrorDescriptor::forHttpStatus($throwable->getStatusCode())->toMessage($message)->toArray(),
            ]));

            return;
        }

        $descriptor = UcpErrorDescriptor::fromThrowable($throwable);

        // A server fault and an unreachable platform profile are an operator's problem even
        // when the client is told about them, so they are logged too. Domain errors are
        // the client's to fix and stay out of the log.
        $logMessage = match (true) {
            $throwable instanceof ConfigurationException => 'UCP request failed because of a server configuration error.',
            $throwable instanceof AgentProfileException => 'UCP request failed because the agent profile could not be fetched.',
            $descriptor->internal => 'Unhandled exception while processing a UCP request.',
            default => null,
        };

        if ($logMessage !== null) {
            $this->logger?->error($logMessage, ['exception' => $throwable]);
        }

        // An internal fault's message names hosts, ports and file paths, so the client gets
        // a fixed sentence and the detail goes to the log above.
        $message = $descriptor->internal ? 'Internal server error.' : $throwable->getMessage();

        $event->setResponse($this->errorResponse(
            $event->getRequest(),
            $message,
            $descriptor->httpStatus,
            $this->messages($descriptor, $throwable, $message),
        ));
    }

    /**
     * One message per validation violation, otherwise one for the failure itself.
     *
     * Every message carries `code` and `severity`, which `types/message_error.json`
     * requires and which only two of these exception types used to supply.
     *
     * @return list<array<string, string>>
     */
    private function messages(UcpErrorDescriptor $descriptor, \Throwable $throwable, string $message): array
    {
        if ($throwable instanceof ValidationException && $throwable->getViolations() !== []) {
            return array_map(
                static fn (string $violation): array => $descriptor->toMessage($violation)->toArray(),
                $throwable->getViolations(),
            );
        }

        return [$descriptor->toMessage($message)->toArray()];
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
