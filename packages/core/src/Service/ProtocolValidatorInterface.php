<?php

declare(strict_types=1);

namespace Ucp\Sdk\Service;

use Ucp\Sdk\Model\RequestContext;

interface ProtocolValidatorInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validateRequest(string $operation, array $payload, RequestContext $context): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function validateResponse(string $operation, array $payload, RequestContext $context): void;
}
