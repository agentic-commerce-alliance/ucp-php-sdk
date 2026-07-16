<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Operation\ShoppingOperationToolSchemas;

final class ShoppingOperationToolSchemasTest extends TestCase
{
    #[Test]
    public function checkoutCompleteAcceptsObjectPayload(): void
    {
        $schema = ShoppingOperationToolSchemas::CHECKOUT_COMPLETE_INPUT;

        self::assertSame(['id', 'payload'], $schema['required']);
        self::assertSame('object', $schema['properties']['payload']['type']);
        self::assertSame(['payment'], $schema['properties']['payload']['required']);
        self::assertArrayHasKey('payment', $schema['properties']['payload']['properties']);
        self::assertArrayHasKey('ap2', $schema['properties']['payload']['properties']);
        self::assertSame(
            'string',
            $schema['properties']['payload']['properties']['ap2']['properties']['checkout_mandate']['type'],
        );
    }
}
