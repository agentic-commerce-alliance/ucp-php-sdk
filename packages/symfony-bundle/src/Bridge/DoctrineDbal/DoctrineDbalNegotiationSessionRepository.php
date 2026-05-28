<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Model\Negotiation\NegotiationSession;
use Ucp\Sdk\Repository\NegotiationSessionRepositoryInterface;

final readonly class DoctrineDbalNegotiationSessionRepository implements NegotiationSessionRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private SchemaBootstrapper $bootstrapper,
        private int $ttlSeconds = 604800,
    ) {
        $this->bootstrapper->ensureSchema();
    }

    public function save(NegotiationSession $session): void
    {
        $data = [
            'id' => $session->id,
            'platform_profile_uri' => $session->platformProfileUri,
            'protocol_version' => $session->protocolVersion,
            'active_capabilities' => json_encode($session->activeCapabilities, JSON_THROW_ON_ERROR),
            'payment_handler_ids' => json_encode($session->paymentHandlerIds, JSON_THROW_ON_ERROR),
            'tenant_identifier' => $session->tenantIdentifier,
            'last_used_at' => $session->lastUsedAt ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM),
            'expires_at' => time() + $this->ttlSeconds,
        ];

        $updated = $this->connection->update('ucp_negotiation_sessions', $data, ['id' => $session->id]);
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('ucp_negotiation_sessions', $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('ucp_negotiation_sessions', $data, ['id' => $session->id]);
        }
    }

    public function find(string $id): ?NegotiationSession
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ucp_negotiation_sessions WHERE id = :id', ['id' => $id]);

        $expiresAt = $row !== false ? ($row['expires_at'] ?? null) : null;
        if ($row !== false && $expiresAt !== null && (int) $expiresAt < time()) {
            return null;
        }

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByProfileUri(string $platformProfileUri, ?string $tenantIdentifier = null): ?NegotiationSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ucp_negotiation_sessions WHERE platform_profile_uri = :platform_profile_uri AND ((tenant_identifier IS NULL AND :tenant_identifier IS NULL) OR tenant_identifier = :tenant_identifier) LIMIT 1',
            [
                'platform_profile_uri' => $platformProfileUri,
                'tenant_identifier' => $tenantIdentifier,
            ],
        );

        $expiresAt = $row !== false ? ($row['expires_at'] ?? null) : null;
        if ($row !== false && $expiresAt !== null && (int) $expiresAt < time()) {
            return null;
        }

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ucp_negotiation_sessions WHERE expires_at IS NOT NULL AND expires_at < :expires_at',
            ['expires_at' => $olderThanUnixTimestamp],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NegotiationSession
    {
        return new NegotiationSession(
            (string) $row['id'],
            (string) $row['platform_profile_uri'],
            (string) $row['protocol_version'],
            is_string($row['active_capabilities'] ?? null) ? json_decode($row['active_capabilities'], true, 512, JSON_THROW_ON_ERROR) : [],
            is_string($row['payment_handler_ids'] ?? null) ? json_decode($row['payment_handler_ids'], true, 512, JSON_THROW_ON_ERROR) : [],
            $row['tenant_identifier'] !== null ? (string) $row['tenant_identifier'] : null,
            $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
        );
    }
}
