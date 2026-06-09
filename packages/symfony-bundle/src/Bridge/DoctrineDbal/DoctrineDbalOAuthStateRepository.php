<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Model\OAuthState;
use Ucp\Sdk\Repository\OAuthStateRepositoryInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\SecretEncryptorInterface;

final readonly class DoctrineDbalOAuthStateRepository implements OAuthStateRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private SecretEncryptorInterface $secretEncryptor,
        private int $authorizationCodeTtl = 600,
    ) {
    }

    public function save(OAuthState $state): void
    {
        $codeHash = hash('sha256', $state->code);
        $expiresAt = $state->expiresAt ?? (time() + $this->authorizationCodeTtl);
        $data = [
            'code_hash' => $codeHash,
            'client_id' => $state->clientId,
            'subject' => $state->subject,
            'refresh_token' => $state->refreshToken !== null ? $this->secretEncryptor->encrypt($state->refreshToken, $codeHash) : null,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
            'created_at' => time(),
        ];

        $updated = $this->connection->update('ucp_oauth_state', $data, ['code_hash' => $codeHash]);
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('ucp_oauth_state', $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('ucp_oauth_state', $data, ['code_hash' => $codeHash]);
        }
    }

    public function consume(string $code): ?OAuthState
    {
        $codeHash = hash('sha256', $code);
        $now = time();

        return $this->connection->transactional(function () use ($code, $codeHash, $now): ?OAuthState {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM ucp_oauth_state WHERE code_hash = :code_hash AND consumed_at IS NULL AND expires_at >= :now',
                ['code_hash' => $codeHash, 'now' => $now],
            );
            if ($row === false) {
                return null;
            }

            $updated = $this->connection->executeStatement(
                'UPDATE ucp_oauth_state SET consumed_at = :consumed_at WHERE code_hash = :code_hash AND consumed_at IS NULL AND expires_at >= :now',
                [
                    'consumed_at' => $now,
                    'code_hash' => $codeHash,
                    'now' => $now,
                ],
            );
            if ($updated !== 1) {
                return null;
            }

            $refreshToken = null;
            if ($row['refresh_token'] !== null) {
                try {
                    $refreshToken = $this->secretEncryptor->decrypt((string) $row['refresh_token'], $codeHash);
                } catch (\Throwable) {
                    $refreshToken = (string) $row['refresh_token'];
                }
            }

            return new OAuthState(
                $code,
                (string) $row['client_id'],
                (string) $row['subject'],
                $refreshToken,
                isset($row['expires_at']) ? (int) $row['expires_at'] : null,
            );
        });
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ucp_oauth_state WHERE (expires_at IS NOT NULL AND expires_at < :expires_at) OR (consumed_at IS NOT NULL AND consumed_at < :consumed_at)',
            [
                'expires_at' => $olderThanUnixTimestamp,
                'consumed_at' => $olderThanUnixTimestamp,
            ],
        );
    }
}
