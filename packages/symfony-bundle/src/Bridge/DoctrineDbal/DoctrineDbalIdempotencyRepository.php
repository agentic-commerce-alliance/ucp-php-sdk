<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Repository\IdempotencyRepositoryInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\SecretEncryptorInterface;

/** @internal */
final class DoctrineDbalIdempotencyRepository implements IdempotencyRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SecretEncryptorInterface $secretEncryptor,
        private readonly int $ttlSeconds = 86400,
        private readonly int $maxStoredResponseBytes = 262144,
    ) {
    }

    public function find(string $key): ?IdempotencyRecord
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ucp_idempotency WHERE idempotency_key = :key', ['key' => $key]);

        if ($row === false) {
            return null;
        }

        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && (int) $expiresAt < time()) {
            return null;
        }

        return new IdempotencyRecord(
            (string) $row['idempotency_key'],
            (string) $row['fingerprint'],
            (string) $row['status'],
            $this->decodeResponseBody((string) $row['idempotency_key'], $row['response_body']),
            $row['status_code'] !== null ? (int) $row['status_code'] : null,
            isset($row['replayable']) ? (bool) $row['replayable'] : true,
        );
    }

    public function save(IdempotencyRecord $record): void
    {
        [$responseBody, $replayable] = $this->encodeResponseBody($record);
        $data = [
            'idempotency_key' => $record->key,
            'fingerprint' => $record->fingerprint,
            'status' => $record->status,
            'response_body' => $responseBody,
            'status_code' => $record->statusCode,
            'replayable' => $replayable ? 1 : 0,
            'expires_at' => time() + $this->ttlSeconds,
        ];

        $updated = $this->connection->update('ucp_idempotency', $data, ['idempotency_key' => $record->key]);
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('ucp_idempotency', $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('ucp_idempotency', $data, ['idempotency_key' => $record->key]);
        }
    }

    public function delete(string $key): void
    {
        $this->connection->executeStatement('DELETE FROM ucp_idempotency WHERE idempotency_key = :key', ['key' => $key]);
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ucp_idempotency WHERE expires_at IS NOT NULL AND expires_at < :expires_at',
            ['expires_at' => $olderThanUnixTimestamp],
        );
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function encodeResponseBody(IdempotencyRecord $record): array
    {
        if ($record->responseBody === null) {
            return [null, $record->replayable];
        }

        if (! $record->replayable) {
            return [null, false];
        }

        $json = json_encode($record->responseBody, JSON_THROW_ON_ERROR);
        if (strlen($json) > $this->maxStoredResponseBytes) {
            return [null, false];
        }

        return [$this->secretEncryptor->encrypt($json, 'idempotency:' . $record->key), true];
    }

    /**
     * @param mixed $stored
     * @return array<string, mixed>|null
     */
    private function decodeResponseBody(string $key, mixed $stored): ?array
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        $decoded = json_decode($this->secretEncryptor->decrypt($stored, 'idempotency:' . $key), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }
}
