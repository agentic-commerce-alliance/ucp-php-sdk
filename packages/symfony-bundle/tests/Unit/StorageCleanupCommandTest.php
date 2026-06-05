<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Ucp\Sdk\Repository\IdempotencyRepositoryInterface;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;
use Ucp\Sdk\Repository\OAuthStateRepositoryInterface;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\StorageCleanupService;
use Ucp\Sdk\Symfony\Command\StorageCleanupCommand;

final class StorageCleanupCommandTest extends TestCase
{
    #[Test]
    public function itPurgesExpiredRecordsAcrossTheDefaultStorageAdapters(): void
    {
        $state = new StorageCleanupState();
        $command = new StorageCleanupCommand(new StorageCleanupService(
            new class ($state) implements OAuthStateRepositoryInterface {
                public function __construct(private readonly StorageCleanupState $state)
                {
                }

                public function save(\Ucp\Sdk\Model\OAuthState $state): void
                {
                }

                public function consume(string $code): ?\Ucp\Sdk\Model\OAuthState
                {
                    return null;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                    $this->state->oauthPurgedAt = $olderThanUnixTimestamp;
                }
            },
            new class ($state) implements IdempotencyRepositoryInterface {
                public function __construct(private readonly StorageCleanupState $state)
                {
                }

                public function find(string $key): ?\Ucp\Sdk\Model\IdempotencyRecord
                {
                    return null;
                }

                public function save(\Ucp\Sdk\Model\IdempotencyRecord $record): void
                {
                }

                public function delete(string $key): void
                {
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                    $this->state->idempotencyPurgedAt = $olderThanUnixTimestamp;
                }
            },
            new class ($state) implements NegotiationSessionRepositoryInterface {
                public function __construct(private readonly StorageCleanupState $state)
                {
                }

                public function save(\Ucp\Sdk\Model\Negotiation\NegotiationSession $session): void
                {
                }

                public function find(string $id): ?\Ucp\Sdk\Model\Negotiation\NegotiationSession
                {
                    return null;
                }

                public function findByProfileUri(string $platformProfileUri, ?string $tenantIdentifier = null): ?\Ucp\Sdk\Model\Negotiation\NegotiationSession
                {
                    return null;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                    $this->state->negotiationPurgedAt = $olderThanUnixTimestamp;
                }
            },
            new class ($state) implements PlatformProfileCacheRepositoryInterface {
                public function __construct(private readonly StorageCleanupState $state)
                {
                }

                public function save(string $uri, \Ucp\Sdk\Model\Profile\PlatformProfile $profile): void
                {
                }

                public function find(string $uri, bool $allowExpired = false): ?\Ucp\Sdk\Model\Profile\PlatformProfile
                {
                    return null;
                }

                public function all(bool $allowExpired = false): array
                {
                    return [];
                }

                public function delete(string $uri): bool
                {
                    return false;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                    $this->state->profileCachePurgedAt = $olderThanUnixTimestamp;
                }
            },
            new class ($state) implements SignatureNonceRepositoryInterface {
                public function __construct(private readonly StorageCleanupState $state)
                {
                }

                public function has(string $scope, string $kid, string $signatureHash): bool
                {
                    return false;
                }

                public function save(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): void
                {
                }

                public function saveIfNew(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): bool
                {
                    return true;
                }

                public function purgeExpired(int $olderThanUnixTimestamp): void
                {
                    $this->state->signaturePurgedAt = $olderThanUnixTimestamp;
                }
            },
            new class ($state) implements ManagedSigningKeyRepositoryInterface {
                public function __construct(private readonly StorageCleanupState $state)
                {
                }

                public function saveManaged(\Ucp\Sdk\Model\Security\ManagedSigningKey $key): void
                {
                }

                public function findManaged(string $kid): ?\Ucp\Sdk\Model\Security\ManagedSigningKey
                {
                    return null;
                }

                public function deleteManaged(string $kid): bool
                {
                    return false;
                }

                public function allManaged(): array
                {
                    return [];
                }

                public function active(): array
                {
                    return [];
                }

                public function purgeRetired(string $olderThanIso8601): void
                {
                    $this->state->signingKeysPurgedBefore = $olderThanIso8601;
                }
            },
            300,
            'P30D',
        ));

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertIsInt($state->oauthPurgedAt);
        self::assertIsInt($state->idempotencyPurgedAt);
        self::assertIsInt($state->negotiationPurgedAt);
        self::assertIsInt($state->profileCachePurgedAt);
        self::assertIsInt($state->signaturePurgedAt);
        self::assertIsString($state->signingKeysPurgedBefore);
        self::assertStringContainsString('Expired SDK storage records purged.', $tester->getDisplay());
    }

    #[Test]
    public function itCanCleanOnlySignatureNoncesAndFallsBackForInvalidRetentionIntervals(): void
    {
        $state = new StorageCleanupState();
        $service = new StorageCleanupService(
            new StorageCleanupOAuthRepository($state),
            new StorageCleanupIdempotencyRepository($state),
            new StorageCleanupNegotiationRepository($state),
            new StorageCleanupPlatformProfileRepository($state),
            new StorageCleanupSignatureNonceRepository($state),
            new StorageCleanupSigningKeyRepository($state),
            300,
            'not-a-duration',
        );

        $service->cleanupSignatureNonces(123);

        self::assertSame(123, $state->signaturePurgedAt);
        self::assertNull($state->oauthPurgedAt);

        $service->cleanup(1_700_000_000);

        self::assertSame(1_700_000_000, $state->oauthPurgedAt);
        self::assertSame(1_699_999_700, $state->signaturePurgedAt);
        self::assertSame('2023-11-14T22:13:20+00:00', $state->signingKeysPurgedBefore);
    }
}

final class StorageCleanupState
{
    public ?int $oauthPurgedAt = null;

    public ?int $idempotencyPurgedAt = null;

    public ?int $negotiationPurgedAt = null;

    public ?int $profileCachePurgedAt = null;

    public ?int $signaturePurgedAt = null;

    public ?string $signingKeysPurgedBefore = null;
}

final class StorageCleanupOAuthRepository implements OAuthStateRepositoryInterface
{
    public function __construct(private readonly StorageCleanupState $state)
    {
    }

    public function save(\Ucp\Sdk\Model\OAuthState $state): void
    {
    }

    public function consume(string $code): ?\Ucp\Sdk\Model\OAuthState
    {
        return null;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->state->oauthPurgedAt = $olderThanUnixTimestamp;
    }
}

final class StorageCleanupIdempotencyRepository implements IdempotencyRepositoryInterface
{
    public function __construct(private readonly StorageCleanupState $state)
    {
    }

    public function find(string $key): ?\Ucp\Sdk\Model\IdempotencyRecord
    {
        return null;
    }

    public function save(\Ucp\Sdk\Model\IdempotencyRecord $record): void
    {
    }

    public function delete(string $key): void
    {
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->state->idempotencyPurgedAt = $olderThanUnixTimestamp;
    }
}

final class StorageCleanupNegotiationRepository implements NegotiationSessionRepositoryInterface
{
    public function __construct(private readonly StorageCleanupState $state)
    {
    }

    public function save(\Ucp\Sdk\Model\Negotiation\NegotiationSession $session): void
    {
    }

    public function find(string $id): ?\Ucp\Sdk\Model\Negotiation\NegotiationSession
    {
        return null;
    }

    public function findByProfileUri(string $platformProfileUri, ?string $tenantIdentifier = null): ?\Ucp\Sdk\Model\Negotiation\NegotiationSession
    {
        return null;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->state->negotiationPurgedAt = $olderThanUnixTimestamp;
    }
}

final class StorageCleanupPlatformProfileRepository implements PlatformProfileCacheRepositoryInterface
{
    public function __construct(private readonly StorageCleanupState $state)
    {
    }

    public function save(string $uri, \Ucp\Sdk\Model\Profile\PlatformProfile $profile): void
    {
    }

    public function find(string $uri, bool $allowExpired = false): ?\Ucp\Sdk\Model\Profile\PlatformProfile
    {
        return null;
    }

    public function all(bool $allowExpired = false): array
    {
        return [];
    }

    public function delete(string $uri): bool
    {
        return false;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->state->profileCachePurgedAt = $olderThanUnixTimestamp;
    }
}

final class StorageCleanupSignatureNonceRepository implements SignatureNonceRepositoryInterface
{
    public function __construct(private readonly StorageCleanupState $state)
    {
    }

    public function has(string $scope, string $kid, string $signatureHash): bool
    {
        return false;
    }

    public function save(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): void
    {
    }

    public function saveIfNew(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): bool
    {
        return true;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->state->signaturePurgedAt = $olderThanUnixTimestamp;
    }
}

final class StorageCleanupSigningKeyRepository implements ManagedSigningKeyRepositoryInterface
{
    public function __construct(private readonly StorageCleanupState $state)
    {
    }

    public function saveManaged(\Ucp\Sdk\Model\Security\ManagedSigningKey $key): void
    {
    }

    public function findManaged(string $kid): ?\Ucp\Sdk\Model\Security\ManagedSigningKey
    {
        return null;
    }

    public function deleteManaged(string $kid): bool
    {
        return false;
    }

    public function allManaged(): array
    {
        return [];
    }

    public function active(): array
    {
        return [];
    }

    public function purgeRetired(string $olderThanIso8601): void
    {
        $this->state->signingKeysPurgedBefore = $olderThanIso8601;
    }
}
