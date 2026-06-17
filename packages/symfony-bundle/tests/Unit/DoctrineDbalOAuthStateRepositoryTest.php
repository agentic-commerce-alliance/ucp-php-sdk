<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\OAuthState;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\DefaultPrivateKeyEncryptor;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalOAuthStateRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalOAuthStateRepositoryTest extends TestCase
{
    #[Test]
    public function itHashesCodesEncryptsRefreshTokensAndConsumesStatesOnce(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalOAuthStateRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            600,
        );

        $repository->save(new OAuthState('code-1', 'client-1', 'subject-1', 'refresh-1'));

        $row = $connection->fetchAssociative('SELECT * FROM ucp_oauth_state WHERE code_hash = :code_hash', [
            'code_hash' => hash('sha256', 'code-1'),
        ]);

        self::assertIsArray($row);
        self::assertSame(hash('sha256', 'code-1'), $row['code_hash']);
        self::assertNotSame('refresh-1', $row['refresh_token']);

        $loaded = $repository->consume('code-1');

        self::assertNotNull($loaded);
        self::assertSame('client-1', $loaded->clientId);
        self::assertSame('refresh-1', $loaded->refreshToken);
        self::assertNull($repository->consume('code-1'));
    }

    #[Test]
    public function itFailsClosedWhenStoredRefreshTokenIsPlaintext(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalOAuthStateRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            600,
        );
        $code = 'legacy-code';
        $codeHash = hash('sha256', $code);

        $connection->insert('ucp_oauth_state', [
            'code_hash' => $codeHash,
            'client_id' => 'client-1',
            'subject' => 'subject-1',
            'refresh_token' => 'plaintext-refresh',
            'expires_at' => time() + 600,
            'consumed_at' => null,
            'created_at' => time(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encrypted private key payload is malformed.');

        $repository->consume($code);
    }

    #[Test]
    public function itFailsClosedWhenRefreshTokenCannotBeDecryptedWithCurrentSecret(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $code = 'rotated-secret-code';
        $codeHash = hash('sha256', $code);

        $connection->insert('ucp_oauth_state', [
            'code_hash' => $codeHash,
            'client_id' => 'client-1',
            'subject' => 'subject-1',
            'refresh_token' => (new DefaultPrivateKeyEncryptor('old-secret'))->encrypt('refresh-1', $codeHash),
            'expires_at' => time() + 600,
            'consumed_at' => null,
            'created_at' => time(),
        ]);

        $repository = new DoctrineDbalOAuthStateRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('new-secret'),
            600,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decrypt private key material.');

        $repository->consume($code);
    }

    #[Test]
    public function itRejectsExpiredCodesAndPurgesExpiredState(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalOAuthStateRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            600,
        );

        $repository->save(new OAuthState('expired-code', 'client-1', 'subject-1', 'refresh-1', time() - 5));

        self::assertNull($repository->consume('expired-code'));

        $repository->purgeExpired(time());

        self::assertSame('0', (string) $connection->fetchOne('SELECT COUNT(*) FROM ucp_oauth_state'));
    }

    #[Test]
    public function itDoesNotCreateRedundantCodeHashIndex(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        (new SchemaBootstrapper($connection))->ensureSchema();

        $indexes = array_change_key_case($connection->createSchemaManager()->listTableIndexes('ucp_oauth_state'), CASE_LOWER);

        self::assertArrayNotHasKey('idx_ucp_oauth_state_code_hash', $indexes);
    }
}
