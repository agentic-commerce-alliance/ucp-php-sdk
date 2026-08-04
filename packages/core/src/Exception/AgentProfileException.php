<?php

declare(strict_types=1);

namespace Ucp\Sdk\Exception;

final class AgentProfileException extends UcpException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unreachable(string $uri, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Platform profile at "%s" could not be fetched: %s',
                $uri,
                $previous?->getMessage() ?? 'the request failed.',
            ),
            'agent_profile_unreachable',
            $previous,
        );
    }

    public static function unavailable(string $uri, int $statusCode): self
    {
        return new self(
            sprintf('Platform profile fetch from "%s" failed with HTTP status %d.', $uri, $statusCode),
            'agent_profile_unavailable',
        );
    }

    public static function tooLarge(string $uri, int $maxResponseBytes): self
    {
        return new self(
            sprintf(
                'Platform profile response from "%s" exceeded the maximum allowed size of %d bytes.',
                $uri,
                $maxResponseBytes,
            ),
            'agent_profile_too_large',
        );
    }

    public static function invalid(string $uri, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Platform profile from "%s" is not a valid UCP profile document: %s', $uri, $reason),
            'agent_profile_invalid',
            $previous,
        );
    }
}
