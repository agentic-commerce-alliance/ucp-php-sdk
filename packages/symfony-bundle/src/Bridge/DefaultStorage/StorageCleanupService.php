<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DefaultStorage;

use Ucp\Sdk\Repository\DiscoveryBudgetRepositoryInterface;
use Ucp\Sdk\Repository\IdempotencyRepositoryInterface;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;
use Ucp\Sdk\Repository\OAuthStateRepositoryInterface;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;

/** @internal */
final class StorageCleanupService
{
    public function __construct(
        private readonly OAuthStateRepositoryInterface $oauthStateRepository,
        private readonly IdempotencyRepositoryInterface $idempotencyRepository,
        private readonly NegotiationSessionRepositoryInterface $negotiationSessionRepository,
        private readonly PlatformProfileCacheRepositoryInterface $platformProfileCacheRepository,
        private readonly SignatureNonceRepositoryInterface $signatureNonceRepository,
        private readonly ManagedSigningKeyRepositoryInterface $managedSigningKeyRepository,
        private readonly int $signatureNonceRetentionSeconds,
        private readonly string $retiredKeyRetention,
        private readonly ?DiscoveryBudgetRepositoryInterface $discoveryBudgetRepository = null,
        private readonly int $discoveryRetentionSeconds = 3600,
    ) {
    }

    public function cleanup(?int $now = null): void
    {
        $reference = $now ?? time();
        $this->oauthStateRepository->purgeExpired($reference);
        $this->idempotencyRepository->purgeExpired($reference);
        $this->negotiationSessionRepository->purgeExpired($reference);
        $this->platformProfileCacheRepository->purgeExpired($reference);
        // Not `$reference`: that would wipe the window in progress and reset every backoff
        // the moment it ends, which turns a fixed footprint into one shaped by the cron schedule.
        $this->discoveryBudgetRepository?->purgeExpired($reference - $this->discoveryRetentionSeconds);
        $this->cleanupSignatureNonces($reference - $this->signatureNonceRetentionSeconds);
        $this->managedSigningKeyRepository->purgeRetired($this->retiredKeyThreshold($reference));
    }

    public function cleanupSignatureNonces(int $olderThanUnixTimestamp): void
    {
        $this->signatureNonceRepository->purgeExpired($olderThanUnixTimestamp);
    }

    private function retiredKeyThreshold(int $reference): string
    {
        try {
            return (new \DateTimeImmutable('@' . $reference))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->sub(new \DateInterval($this->retiredKeyRetention))
                ->format(DATE_ATOM);
        } catch (\Throwable) {
            return (new \DateTimeImmutable('@' . $reference))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        }
    }
}
