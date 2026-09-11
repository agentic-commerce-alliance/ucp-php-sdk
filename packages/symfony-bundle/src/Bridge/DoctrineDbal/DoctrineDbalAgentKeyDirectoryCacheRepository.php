<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Model\Security\AgentKeyDirectory;
use Ucp\Sdk\Repository\AgentKeyDirectoryCacheRepositoryInterface;

/**
 * Caches the key directories fetched from `Signature-Agent` URLs.
 *
 * Its own table rather than a column on the profile cache: the two are fetched from different
 * URLs, expire on different schedules, and answer different questions. Sharing storage would
 * mean a profile eviction silently dropping an agent's keys.
 *
 * @internal
 */
final class DoctrineDbalAgentKeyDirectoryCacheRepository implements AgentKeyDirectoryCacheRepositoryInterface
{
    private const TABLE = 'ucp_agent_key_directory_cache';

    public function __construct(
        private readonly Connection $connection,
        private readonly int $ttlSeconds = 600,
    ) {
    }

    public function save(string $uri, AgentKeyDirectory $directory): void
    {
        $data = [
            'uri' => $uri,
            'payload' => json_encode($directory->toArray(), JSON_THROW_ON_ERROR),
            'expires_at' => time() + $this->ttlSeconds,
        ];

        $updated = $this->connection->update(self::TABLE, $data, ['uri' => $uri]);
        if ($updated > 0) {
            return;
        }

        // Update-then-insert with a catch, rather than an upsert: the portable spellings differ
        // per platform and this repository is tested against both MySQL and Postgres.
        try {
            $this->connection->insert(self::TABLE, $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update(self::TABLE, $data, ['uri' => $uri]);
        }
    }

    public function find(string $uri, bool $allowExpired = false): ?AgentKeyDirectory
    {
        $row = $this->connection->fetchAssociative(
            'SELECT payload, expires_at FROM ' . self::TABLE . ' WHERE uri = :uri',
            ['uri' => $uri],
        );

        if ($row === false) {
            return null;
        }

        if (! $allowExpired && isset($row['expires_at']) && (int) $row['expires_at'] < time()) {
            return null;
        }

        $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

        return is_array($payload) ? AgentKeyDirectory::fromArray($uri, $payload) : null;
    }

    public function delete(string $uri): bool
    {
        return $this->connection->delete(self::TABLE, ['uri' => $uri]) > 0;
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE expires_at IS NOT NULL AND expires_at < :cutoff',
            ['cutoff' => $olderThanUnixTimestamp],
        );
    }
}
