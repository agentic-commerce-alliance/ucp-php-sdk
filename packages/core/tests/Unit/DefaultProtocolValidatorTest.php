<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Service\DefaultProtocolValidator;
use Ucp\Sdk\Model\RequestContext;
use Ucp\Sdk\Service\SchemaValidatorInterface;

final class DefaultProtocolValidatorTest extends TestCase
{
    #[Test]
    public function itDelegatesRequestValidationToTheSchemaValidator(): void
    {
        $schemaValidator = $this->createMock(SchemaValidatorInterface::class);
        $schemaValidator
            ->expects($this->once())
            ->method('validate')
            ->with('checkout.create.request', ['ok' => true]);
        $validator = new DefaultProtocolValidator($schemaValidator);

        $validator->validateRequest('checkout.create', ['ok' => true], new RequestContext('merchant.example'));
    }

    #[Test]
    public function itPropagatesResponseValidationFailures(): void
    {
        $schemaValidator = $this->createMock(SchemaValidatorInterface::class);
        $schemaValidator
            ->method('validate')
            ->willThrowException(new \RuntimeException('missing response schema'));
        $validator = new DefaultProtocolValidator($schemaValidator);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing response schema');

        $validator->validateResponse('checkout.create', ['ok' => true], new RequestContext('merchant.example'));
    }
}
