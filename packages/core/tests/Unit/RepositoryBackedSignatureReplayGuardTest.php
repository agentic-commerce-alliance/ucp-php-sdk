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
        $state = new SavedReplayState();
        $guard = new RepositoryBackedSignatureReplayGuard(
            new class ($state) implements SignatureNonceRepositoryInterface {
                public function __construct(private SavedReplayState $state)
                {
                }

                public function has(string $scope, string $kid, string $signatureHash): bool
                {
                    return false;
                }

                public function save(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): void
                {
                    $this->state->saved = [$scope, $kid, $signatureHash, $createdAt];
                }

                public function saveIfNew(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): bool
                {
                    $this->state->saved = [$scope, $kid, $signatureHash, $createdAt];

                    return true;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                }
            },
        );

        $guard->rememberOrThrow('tenant-a', 'kid-1', 'signature', 42);

        self::assertSame(['tenant-a', 'kid-1', hash('sha256', 'signature'), 42], $state->saved);
    }

    #[Test]
    public function itRejectsSignatureReplays(): void
    {
        $guard = new RepositoryBackedSignatureReplayGuard(
            new class () implements SignatureNonceRepositoryInterface {
                public function has(string $scope, string $kid, string $signatureHash): bool
                {
                    return true;
                }

                public function save(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): void
                {
                }

                public function saveIfNew(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): bool
                {
                    return false;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                }
            },
        );

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Request signature replay detected.');

        $guard->rememberOrThrow('tenant-a', 'kid-1', 'signature', 42);
    }
}

final class SavedReplayState
{
    /** @var list<int|string|null> */
    public array $saved = [];
}
