<?php

declare(strict_types=1);

namespace Ucp\Sdk\Exception;

final class ValidationException extends UcpException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
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
