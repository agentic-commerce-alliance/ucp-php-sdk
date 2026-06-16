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
        $signatureNonces = $this->createMock(SignatureNonceRepositoryInterface::class);
        $signatureNonces
            ->expects($this->once())
            ->method('purgeExpired')
            ->with($this->isType('int'))
            ->willReturnCallback(static function (int $olderThanUnixTimestamp) use ($state): void {
                $state->purgedThreshold = $olderThanUnixTimestamp;
            });
        $command = new PurgeSignatureNoncesCommand(
            new StorageCleanupService(
                $this->createMock(OAuthStateRepositoryInterface::class),
                $this->createStub(IdempotencyRepositoryInterface::class),
                $this->createMock(NegotiationSessionRepositoryInterface::class),
                $this->createMock(PlatformProfileCacheRepositoryInterface::class),
                $signatureNonces,
                $this->createMock(ManagedSigningKeyRepositoryInterface::class),
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
