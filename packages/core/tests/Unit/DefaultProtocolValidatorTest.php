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
        $state = new CollectedSchemasState();
        $validator = new DefaultProtocolValidator(
            new class ($state) implements SchemaValidatorInterface {
                public function __construct(private CollectedSchemasState $state)
                {
                }

                public function validate(string $schemaName, array $payload): void
                {
                    $this->state->schemas[] = $schemaName;
                }
            },
        );

        $validator->validateRequest('checkout.create', ['ok' => true], new RequestContext('merchant.example'));

        self::assertSame(['checkout.create.request'], $state->schemas);
    }

    #[Test]
    public function itPropagatesResponseValidationFailures(): void
    {
        $validator = new DefaultProtocolValidator(
            new class () implements SchemaValidatorInterface {
                public function validate(string $schemaName, array $payload): void
                {
                    throw new \RuntimeException('missing response schema');
                }
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing response schema');

        $validator->validateResponse('checkout.create', ['ok' => true], new RequestContext('merchant.example'));
    }
}

final class CollectedSchemasState
{
    /** @var list<string> */
    public array $schemas = [];
}
