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
use Ucp\Sdk\Symfony\Command\PurgeSignatureNoncesCommand;

final class PurgeSignatureNoncesCommandTest extends TestCase
{
    #[Test]
    public function itPurgesSignatureNoncesOlderThanTheConfiguredWindow(): void
    {
        $state = new PurgedThresholdState();
        $command = new PurgeSignatureNoncesCommand(
            new StorageCleanupService(
                new class () implements OAuthStateRepositoryInterface {
                    public function save(\Ucp\Sdk\Model\OAuthState $state): void
                    {
                    }

                    public function consume(string $code): ?\Ucp\Sdk\Model\OAuthState
                    {
                        return null;
                    }

                    public function purgeExpired(int $olderThanUnixTimestamp): void
                    {
                    }
                },
                new class () implements IdempotencyRepositoryInterface {
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
                    }
                },
                new class () implements NegotiationSessionRepositoryInterface {
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
                    }
                },
                new class () implements PlatformProfileCacheRepositoryInterface {
                    public function save(string $uri, \Ucp\Sdk\Model\Profile\PlatformProfile $profile): void
                    {
                    }

                    public function find(string $uri, bool $allowExpired = false): ?\Ucp\Sdk\Model\Profile\PlatformProfile
                    {
                        return null;
                    }

                    public function purgeExpired(int $olderThanUnixTimestamp): void
                    {
                    }
                },
                new class ($state) implements SignatureNonceRepositoryInterface {
                    public function __construct(private PurgedThresholdState $state)
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
                        $this->state->purgedThreshold = $olderThanUnixTimestamp;
                    }
                },
                new class () implements ManagedSigningKeyRepositoryInterface {
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
                    }
                },
                3600,
                'P30D',
            ),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--older-than-seconds' => '120',
        ]);

        self::assertSame(0, $status);
        self::assertIsInt($state->purgedThreshold);
        self::assertStringContainsString('Purged signature nonces older than 120 seconds.', $tester->getDisplay());
    }
}

final class PurgedThresholdState
{
    public ?int $purgedThreshold = null;
}
