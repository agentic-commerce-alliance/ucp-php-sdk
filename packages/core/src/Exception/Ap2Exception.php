<?php

declare(strict_types=1);

namespace Ucp\Sdk\Exception;

/**
 * AP2 mandate or payment authorization failure with a stable protocol error code.
 *
 * Reference: https://ucp.dev/latest/specification/ap2-mandates/
 */
final class Ap2Exception extends UcpException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        private readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<string>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
