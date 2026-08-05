<?php

declare(strict_types=1);

namespace Ucp\Sdk\Model\Common;

use Ucp\Sdk\Exception\AgentProfileException;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Exception\NegotiationException;
use Ucp\Sdk\Exception\OAuthException;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Exception\UcpException;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Exception\ValidationException;

/**
 * The transport-agnostic description of a failed UCP operation.
 *
 * `types/message_error.json` requires `code` AND `severity` on every error message, so a
 * body carrying neither is not conformant no matter which transport produced it. Before
 * this existed each transport decided for itself: the HTTP ExceptionListener attached
 * them for two of the ten exception types and nothing else, and consumers answering over
 * another transport — an MCP tool, an A2A task — had no mapping at all and reported every
 * failure as an untyped internal error.
 *
 * One mapping, so a caller only has to choose how to render it.
 */
final class UcpErrorDescriptor
{
    private function __construct(
        /** Kind of failure, for consumers that report a category rather than a status code. */
        public readonly string $type,
        /** `types/error_code.json` — freeform by specification; the codes it lists are examples. */
        public readonly string $code,
        /** `types/message_error.json` severity enum. */
        public readonly string $severity,
        public readonly int $httpStatus,
        /**
         * Whether this is an internal fault rather than a domain error.
         *
         * A caller serving an untrusted client MUST NOT put `getMessage()` of an internal
         * fault in the response — it carries hostnames, ports and file paths. Log it
         * instead: that leaks nothing and is the difference between a five-minute
         * diagnosis and an afternoon.
         */
        public readonly bool $internal,
    ) {
    }

    public static function fromThrowable(\Throwable $throwable): self
    {
        return match (true) {
            $throwable instanceof ValidationException => new self('validation', 'invalid_request', 'recoverable', 422, false),
            $throwable instanceof IdempotencyConflictException => new self('idempotency_conflict', 'idempotency_conflict', 'unrecoverable', 409, false),
            $throwable instanceof SignatureException => new self('signature', 'signature_invalid', 'unrecoverable', 401, false),
            $throwable instanceof OAuthException => new self('oauth', 'identity_required', 'requires_buyer_input', 400, false),
            $throwable instanceof NegotiationException => new self('negotiation', $throwable->errorCode, 'unrecoverable', 400, false),
            $throwable instanceof UnsupportedCapabilityException => new self('unsupported_capability', 'capability_unsupported', 'unrecoverable', 501, false),
            $throwable instanceof ResourceNotFoundException => new self('not_found', 'not_found', 'unrecoverable', 404, false),
            // Internal only in the sense of who has to fix it. Both transports already put
            // this message in the response — it names the setting an operator has to change
            // and nothing else — so marking it internal here would be a behaviour change
            // dressed up as a mapping.
            $throwable instanceof ConfigurationException => new self('configuration', 'server_misconfigured', 'unrecoverable', 500, false),
            // Recoverable on purpose: the profile lives on the platform's side, so the same
            // request can succeed once it answers again.
            $throwable instanceof AgentProfileException => new self('ucp', $throwable->errorCode, 'recoverable', 424, false),
            $throwable instanceof UcpException => new self('ucp', 'request_failed', 'unrecoverable', 500, false),
            default => new self('internal', 'internal_error', 'unrecoverable', 500, true),
        };
    }

    /**
     * For a framework exception that carries its own HTTP status.
     *
     * Never internal: such an exception is raised with both a status and a message chosen
     * for the client, so only the code and the severity are derived here.
     */
    public static function forHttpStatus(int $httpStatus): self
    {
        return $httpStatus >= 500
            ? new self('server_error', 'request_failed', 'unrecoverable', $httpStatus, false)
            : new self('request', 'invalid_request', 'recoverable', $httpStatus, false);
    }

    public function toMessage(string $content, ?string $path = null): Message
    {
        return new Message('error', $content, $this->severity, $this->code, $path);
    }
}
