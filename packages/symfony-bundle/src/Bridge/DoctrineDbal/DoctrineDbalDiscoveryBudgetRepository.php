<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Repository\DiscoveryBudgetRepositoryInterface;

/** @internal */
final class DoctrineDbalDiscoveryBudgetRepository implements DiscoveryBudgetRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function countFetch(int $windowStart): int
    {
        // An atomic increment, so concurrent workers share one window count instead of each
        // reading the same value and writing it back plus one.
        $updated = $this->connection->executeStatement(
            'UPDATE ucp_discovery_windows SET fetches = fetches + 1 WHERE window_start = :window_start',
            ['window_start' => $windowStart],
        );
        if ($updated === 0) {
            try {
                $this->connection->insert('ucp_discovery_windows', ['window_start' => $windowStart, 'fetches' => 1]);

                return 1;
            } catch (UniqueConstraintViolationException) {
                $this->connection->executeStatement(
                    'UPDATE ucp_discovery_windows SET fetches = fetches + 1 WHERE window_start = :window_start',
                    ['window_start' => $windowStart],
                );
            }
        }

        return (int) $this->connection->fetchOne(
            'SELECT fetches FROM ucp_discovery_windows WHERE window_start = :window_start',
            ['window_start' => $windowStart],
        );
    }

    public function retryAfter(string $uri): ?int
    {
        $retryAfter = $this->connection->fetchOne(
            'SELECT retry_after FROM ucp_discovery_backoff WHERE uri = :uri',
            ['uri' => $uri],
        );

        return $retryAfter === false ? null : (int) $retryAfter;
    }

    public function recordFailure(string $uri, int $retryAfter): int
    {
        $updated = $this->connection->executeStatement(
            'UPDATE ucp_discovery_backoff SET failures = failures + 1, retry_after = :retry_after WHERE uri = :uri',
            ['uri' => $uri, 'retry_after' => $retryAfter],
        );
        if ($updated === 0) {
            try {
                $this->connection->insert('ucp_discovery_backoff', ['uri' => $uri, 'failures' => 1, 'retry_after' => $retryAfter]);

                return 1;
            } catch (UniqueConstraintViolationException) {
                $this->connection->executeStatement(
                    'UPDATE ucp_discovery_backoff SET failures = failures + 1, retry_after = :retry_after WHERE uri = :uri',
                    ['uri' => $uri, 'retry_after' => $retryAfter],
                );
            }
        }

        return (int) $this->connection->fetchOne(
            'SELECT failures FROM ucp_discovery_backoff WHERE uri = :uri',
            ['uri' => $uri],
        );
    }

    public function extendBackoff(string $uri, int $retryAfter): void
    {
        $this->connection->update('ucp_discovery_backoff', ['retry_after' => $retryAfter], ['uri' => $uri]);
    }

    public function clearFailures(string $uri): void
    {
        $this->connection->delete('ucp_discovery_backoff', ['uri' => $uri]);
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ucp_discovery_windows WHERE window_start < :reference',
            ['reference' => $olderThanUnixTimestamp],
        );
        $this->connection->executeStatement(
            'DELETE FROM ucp_discovery_backoff WHERE retry_after < :reference',
            ['reference' => $olderThanUnixTimestamp],
        );
    }
}
