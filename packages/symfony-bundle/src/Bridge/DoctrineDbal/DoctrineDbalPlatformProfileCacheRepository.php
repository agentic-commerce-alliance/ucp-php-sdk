<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Model\Profile\CachedPlatformProfile;
use Ucp\Sdk\Model\Profile\PlatformProfile;
use Ucp\Sdk\Repository\RevalidatingPlatformProfileCacheRepositoryInterface;

/** @internal */
final class DoctrineDbalPlatformProfileCacheRepository implements RevalidatingPlatformProfileCacheRepositoryInterface
{
    /**
     * @param int|null $maxEntries the fixed size of the cache; null keeps it unbounded. The
     *                             specification asks for a bounded cache so that discovery of
     *                             unknown platforms cannot grow storage without limit.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly int $ttlSeconds = 600,
        private readonly ?int $maxEntries = null,
    ) {
    }

    public function save(string $uri, PlatformProfile $profile): void
    {
        $this->saveEntry($uri, $profile, time() + $this->ttlSeconds);
    }

    public function saveEntry(string $uri, PlatformProfile $profile, int $expiresAt, ?string $etag = null): void
    {
        $data = [
            'uri' => $uri,
            'payload' => json_encode($profile->toArray(), JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'etag' => $etag,
        ];

        $updated = $this->connection->update('ucp_platform_profile_cache', $data, ['uri' => $uri]);
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('ucp_platform_profile_cache', $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('ucp_platform_profile_cache', $data, ['uri' => $uri]);

            return;
        }

        $this->evictOverflow($uri);
    }

    /**
     * Keeps the cache at `$maxEntries` by dropping the entries that expired or expire first,
     * the entry just written excepted. An entry only stays stale while nobody asks for it, since
     * a request under a free budget writes it back fresh, so the oldest expiry is the closest the
     * table comes to least recently used, the eviction the specification gives as its example.
     */
    private function evictOverflow(string $keep): void
    {
        if ($this->maxEntries === null) {
            return;
        }

        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ucp_platform_profile_cache');
        $overflow = $count - $this->maxEntries;
        if ($overflow <= 0) {
            return;
        }

        $uris = $this->connection->fetchFirstColumn(
            'SELECT uri FROM ucp_platform_profile_cache WHERE uri <> :keep ORDER BY expires_at ASC, uri ASC LIMIT ' . $overflow,
            ['keep' => $keep],
        );
        foreach ($uris as $evicted) {
            $this->connection->delete('ucp_platform_profile_cache', ['uri' => (string) $evicted]);
        }
    }

    public function find(string $uri, bool $allowExpired = false): ?PlatformProfile
    {
        $entry = $this->findEntry($uri);
        if ($entry === null) {
            return null;
        }

        if (! $allowExpired && ! $entry->isFresh()) {
            return null;
        }

        return $entry->profile;
    }

    public function findEntry(string $uri): ?CachedPlatformProfile
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payload, expires_at, etag FROM ucp_platform_profile_cache WHERE uri = :uri',
            ['uri' => $uri],
        );
        if ($row === false) {
            return null;
        }

        return new CachedPlatformProfile(
            PlatformProfile::fromArray(json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR)),
            // A row without an expiry predates expiries being recorded; it never goes stale on
            // its own, which is what find() has always done with it.
            isset($row['expires_at']) ? (int) $row['expires_at'] : PHP_INT_MAX,
            isset($row['etag']) && $row['etag'] !== '' ? (string) $row['etag'] : null,
        );
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
