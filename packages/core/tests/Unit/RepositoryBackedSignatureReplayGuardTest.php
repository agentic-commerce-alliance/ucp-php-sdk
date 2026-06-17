<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\RepositoryBackedSignatureReplayGuard;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;

final class RepositoryBackedSignatureReplayGuardTest extends TestCase
{
    #[Test]
    public function itStoresNewSignatureNonces(): void
    {
        $repository = $this->createMock(SignatureNonceRepositoryInterface::class);
        $repository
            ->expects($this->once())
            ->method('saveIfNew')
            ->with('tenant-a', 'kid-1', hash('sha256', 'signature'), 42)
            ->willReturn(true);
        $guard = new RepositoryBackedSignatureReplayGuard($repository);

        $guard->rememberOrThrow('tenant-a', 'kid-1', 'signature', 42);
    }

    #[Test]
    public function itRejectsSignatureReplays(): void
    {
        $repository = $this->createMock(SignatureNonceRepositoryInterface::class);
        $repository
            ->method('saveIfNew')
            ->willReturn(false);
        $guard = new RepositoryBackedSignatureReplayGuard($repository);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Request signature replay detected.');

        $guard->rememberOrThrow('tenant-a', 'kid-1', 'signature', 42);
    }
}
