<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpResponseStatus;
use Ucp\Sdk\Model\Protocol\UcpEnvelope;
use Ucp\Sdk\Model\Protocol\UcpOperationPayload;
use Ucp\Sdk\Model\Protocol\UcpOperationResponse;

final class UcpOperationResponseTest extends TestCase
{
    #[Test]
    public function itSerializesTypedPayloadsWithTheProtocolEnvelope(): void
    {
        $response = new UcpOperationResponse(
            new class () implements UcpOperationPayload {
                /**
                 * @return array{id: string}
                 */
                public function toArray(): array
                {
                    return ['id' => 'cart-1'];
                }

                /**
                 * @return array{id: string}
                 */
                public function jsonSerialize(): array
                {
                    return $this->toArray();
                }
            },
            UcpEnvelope::response('2026-04-08', UcpResponseStatus::Success, UcpCapability::Cart),
        );

        $payload = $response->toArray();
        self::assertSame('cart-1', $payload['id']);
        self::assertIsArray($payload['ucp']);
        self::assertSame('success', $payload['ucp']['status']);
    }
}
