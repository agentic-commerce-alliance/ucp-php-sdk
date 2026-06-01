<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\PlatformProfileCacheRepositoryInterface;

final readonly class DoctrineDbalPlatformProfileCacheRepository implements PlatformProfileCacheRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private SchemaBootstrapper $bootstrapper,
        private int $ttlSeconds = 600,
    ) {
        $this->bootstrapper->ensureSchema();
    }

    public function save(string $uri, PlatformProfile $profile): void
    {
        $data = [
            'uri' => $uri,
            'payload' => json_encode($profile->toArray(), JSON_THROW_ON_ERROR),
            'expires_at' => time() + $this->ttlSeconds,
        ];

        $updated = $this->connection->update('ucp_platform_profile_cache', $data, ['uri' => $uri]);
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('ucp_platform_profile_cache', $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('ucp_platform_profile_cache', $data, ['uri' => $uri]);
        }
    }

    public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
    {
        $row = $this->connection->fetchAssociative('SELECT payload, expires_at FROM ucp_platform_profile_cache WHERE uri = :uri', ['uri' => $uri]);

        if ($row === false) {
            return null;
        }

        if (
            ! $allowExpired
            && isset($row['expires_at'])
            && (int) $row['expires_at'] < time()
        ) {
            return null;
        }

        return PlatformProfile::fromArray(json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function all(bool $allowExpired = false): array
    {
        $sql = 'SELECT uri, payload, expires_at FROM ucp_platform_profile_cache';
        if (!$allowExpired) {
            $sql .= ' WHERE expires_at IS NULL OR expires_at >= :now';
        }

        $rows = $this->connection->fetchAllAssociative(
            $sql.' ORDER BY uri ASC',
            $allowExpired ? [] : ['now' => time()],
        );

        $profiles = [];
        foreach ($rows as $row) {
            $profiles[(string) $row['uri']] = PlatformProfile::fromArray(json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR));
        }

        return $profiles;
    }

    public function delete(string $uri): bool
    {
        return $this->connection->delete('ucp_platform_profile_cache', ['uri' => $uri]) > 0;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ucp_platform_profile_cache WHERE expires_at IS NOT NULL AND expires_at < :expires_at',
            ['expires_at' => $olderThanUnixTimestamp],
        );
    }
}
