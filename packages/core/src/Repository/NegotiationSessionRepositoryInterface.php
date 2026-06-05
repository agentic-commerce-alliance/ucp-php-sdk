<?php

declare(strict_types=1);

namespace Ucp\Sdk\Repository;

use Ucp\Sdk\Model\Negotiation\NegotiationSession;

interface NegotiationSessionRepositoryInterface
{
    public function save(NegotiationSession $session): void;

    public function find(string $id): ?NegotiationSession;

    public function findByProfileUri(string $platformProfileUri, ?string $tenantIdentifier = null): ?NegotiationSession;

    public function purgeExpired(int $olderThanUnixTimestamp): void;
}
