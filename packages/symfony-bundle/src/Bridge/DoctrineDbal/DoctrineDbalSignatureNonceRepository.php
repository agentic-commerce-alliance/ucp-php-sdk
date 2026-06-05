<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Repository\SignatureNonceRepositoryInterface;

final readonly class DoctrineDbalSignatureNonceRepository implements SignatureNonceRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private SchemaBootstrapper $bootstrapper,
    ) {
        $this->bootstrapper->ensureSchema();
    }

    public function has(string $scope, string $kid, string $signatureHash): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM ucp_signature_nonces WHERE scope = :scope AND kid = :kid AND signature_hash = :signature_hash',
            [
                'scope' => $scope,
                'kid' => $kid,
                'signature_hash' => $signatureHash,
            ],
        ) !== false;
    }

    public function save(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): void
    {
        $data = [
            'scope' => $scope,
            'kid' => $kid,
            'signature_hash' => $signatureHash,
            'created_at' => $createdAt ?? time(),
        ];

        $updated = $this->connection->update(
            'ucp_signature_nonces',
            ['created_at' => $data['created_at']],
            [
                'scope' => $scope,
                'kid' => $kid,
                'signature_hash' => $signatureHash,
            ],
        );
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('ucp_signature_nonces', $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update(
                'ucp_signature_nonces',
                ['created_at' => $data['created_at']],
                [
                    'scope' => $scope,
                    'kid' => $kid,
                    'signature_hash' => $signatureHash,
                ],
            );
        }
    }

    public function saveIfNew(string $scope, string $kid, string $signatureHash, ?int $createdAt = null): bool
    {
        try {
            $this->connection->insert('ucp_signature_nonces', [
                'scope' => $scope,
                'kid' => $kid,
                'signature_hash' => $signatureHash,
                'created_at' => $createdAt ?? time(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function purgeExpired(int $olderThanUnixTimestamp): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ucp_signature_nonces WHERE created_at IS NOT NULL AND created_at < :created_at',
            ['created_at' => $olderThanUnixTimestamp],
        );
    }
}
