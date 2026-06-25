<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpResponseStatus;
use Ucp\Sdk\Model\Protocol\UcpEnvelope;

final class UcpEnvelopeTest extends TestCase
{
    #[Test]
    public function itSerializesEmptyRegistriesAsJsonObjects(): void
    {
        $payload = json_decode(json_encode(
            UcpEnvelope::response('2026-04-08', UcpResponseStatus::Success, UcpCapability::Cart),
            JSON_THROW_ON_ERROR,
        ), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('2026-04-08', $payload['version']);
        self::assertSame('success', $payload['status']);
        self::assertSame([['version' => '2026-04-08']], $payload['capabilities']['dev.ucp.shopping.cart']);
        self::assertSame([], (array) $payload['services']);
        self::assertSame([], (array) $payload['payment_handlers']);
    }
}
