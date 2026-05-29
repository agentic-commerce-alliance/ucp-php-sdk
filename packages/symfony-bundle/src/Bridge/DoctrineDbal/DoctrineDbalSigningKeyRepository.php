<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Bridge\DoctrineDbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Repository\ManagedSigningKeyRepositoryInterface;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\SecretEncryptorInterface;

final readonly class DoctrineDbalSigningKeyRepository implements ManagedSigningKeyRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private SchemaBootstrapper $bootstrapper,
        private SecretEncryptorInterface $secretEncryptor,
    ) {
        $this->bootstrapper->ensureSchema();
    }

    public function saveManaged(ManagedSigningKey $key): void
    {
        $data = [
            'kid' => $key->kid,
            'public_key_pem' => $key->publicKeyPem,
            'private_key_pem' => $this->secretEncryptor->encrypt($key->privateKeyPem, $key->kid),
            'algorithm' => $key->algorithm,
            'key_type' => $key->keyType,
            'key_use' => $key->use,
            'status' => $key->status,
            'curve' => $key->curve,
            'created_at' => $key->createdAt,
            'retire_at' => $key->retireAt,
        ];

        $updated = $this->connection->update('ucp_signing_keys', $data, ['kid' => $key->kid]);
        if ($updated > 0) {
            return;
        }

        try {
            $this->connection->insert('ucp_signing_keys', $data);
        } catch (UniqueConstraintViolationException) {
            $this->connection->update('ucp_signing_keys', $data, ['kid' => $key->kid]);
        }
    }

    public function findManaged(string $kid): ?ManagedSigningKey
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ucp_signing_keys WHERE kid = :kid', ['kid' => $kid]);

        if ($row === false) {
            return null;
        }

        return $this->hydrateManaged($row);
    }

    public function allManaged(): array
    {
        return array_map(
            fn (array $row): ManagedSigningKey => $this->hydrateManaged($row),
            $this->connection->fetchAllAssociative('SELECT * FROM ucp_signing_keys ORDER BY kid ASC'),
        );
    }

    public function active(): array
    {
        return array_map(
            fn (array $row): ManagedSigningKey => $this->hydrateManaged($row),
            $this->connection->fetchAllAssociative(
                'SELECT * FROM ucp_signing_keys WHERE status IN (:statuses) ORDER BY kid ASC',
                ['statuses' => ['active', 'retiring']],
                ['statuses' => $this->getStringArrayParameterType()],
            ),
        );
    }

    public function purgeRetired(string $olderThanIso8601): void
    {
        $threshold = strtotime($olderThanIso8601);
        if ($threshold === false) {
            return;
        }

        foreach ($this->connection->fetchAllAssociative('SELECT kid, retire_at, status FROM ucp_signing_keys WHERE status = :status AND retire_at IS NOT NULL', ['status' => 'retired']) as $row) {
            $retireAt = strtotime((string) $row['retire_at']);
            if ($retireAt === false || $retireAt >= $threshold) {
                continue;
            }

            $this->connection->executeStatement('DELETE FROM ucp_signing_keys WHERE kid = :kid', ['kid' => $row['kid']]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateManaged(array $row): ManagedSigningKey
    {
        return new ManagedSigningKey(
            (string) $row['kid'],
            (string) $row['public_key_pem'],
            $this->secretEncryptor->decrypt((string) $row['private_key_pem'], (string) $row['kid']),
            (string) $row['algorithm'],
            (string) ($row['key_type'] ?? 'EC'),
            (string) ($row['key_use'] ?? 'sig'),
            (string) ($row['status'] ?? 'active'),
            isset($row['curve']) ? (string) $row['curve'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['retire_at']) ? (string) $row['retire_at'] : null,
        );
    }

    private function getStringArrayParameterType(): mixed
    {
        if (class_exists(ArrayParameterType::class)) {
            return ArrayParameterType::STRING;
        }

        return Connection::PARAM_STR_ARRAY;
    }
}
