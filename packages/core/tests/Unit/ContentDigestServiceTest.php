<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\ContentDigestService;

final class ContentDigestServiceTest extends TestCase
{
    #[Test]
    public function itCreatesAndVerifiesMatchingDigests(): void
    {
        $service = new ContentDigestService();
        $digest = $service->create('payload');

        self::assertStringStartsWith('sha-256=:', $digest);
        self::assertStringEndsWith(':', $digest);

        $service->verify('payload', $digest);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function itRejectsMissingDigests(): void
    {
        $service = new ContentDigestService();

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Missing Content-Digest header.');

        $service->verify('payload', null);
    }

    #[Test]
    public function itRejectsMismatchedDigests(): void
    {
        $service = new ContentDigestService();

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Invalid Content-Digest header.');

        $service->verify('payload', $service->create('different'));
    }
}
