<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\ProtocolValidatorInterface;
use Ucp\Sdk\Service\SchemaValidatorInterface;

final readonly class DefaultProtocolValidator implements ProtocolValidatorInterface
{
    public function __construct(
        private SchemaValidatorInterface $schemaValidator,
    ) {
    }

    public function validateRequest(string $operation, array $payload, RequestContext $context): void
    {
        $this->schemaValidator->validate($operation . '.request', $payload);
    }

    public function validateResponse(string $operation, array $payload, RequestContext $context): void
    {
        $this->schemaValidator->validate($operation . '.response', $payload);
    }
}
