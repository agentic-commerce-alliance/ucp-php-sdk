<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\DefaultPrivateKeyEncryptor;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalIdempotencyRepository;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\SchemaBootstrapper;

final class DoctrineDbalIdempotencyRepositoryTest extends TestCase
{
    #[Test]
    public function itEncryptsReplayableResponseBodiesAtRest(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalIdempotencyRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            86400,
            1024,
        );

        $record = new IdempotencyRecord('idem-1', 'fp-1', 'completed', ['ok' => true], 201);
        $repository->save($record);

        $row = $connection->fetchAssociative('SELECT * FROM ucp_idempotency WHERE idempotency_key = :key', ['key' => 'idem-1']);
        self::assertIsArray($row);
        self::assertNotSame('{"ok":true}', $row['response_body']);

        $loaded = $repository->find('idem-1');

        self::assertNotNull($loaded);
        self::assertSame(['ok' => true], $loaded->responseBody);
        self::assertTrue($loaded->replayable);
    }

    #[Test]
    public function itMarksOversizedResponsesAsNonReplayableAndPurgesExpiredRows(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalIdempotencyRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            86400,
            8,
        );

        $record = new IdempotencyRecord('idem-2', 'fp-2', 'completed', ['message' => 'too large'], 201);
        $repository->save($record);

        $loaded = $repository->find('idem-2');
        self::assertNotNull($loaded);
        self::assertNull($loaded->responseBody);
        self::assertFalse($loaded->replayable);

        $connection->executeStatement(
            'UPDATE ucp_idempotency SET expires_at = :expires_at WHERE idempotency_key = :key',
            ['expires_at' => time() - 5, 'key' => 'idem-2'],
        );

        $repository->purgeExpired(time());

        self::assertNull($repository->find('idem-2'));
    }

    #[Test]
    public function itReportsDuplicatePendingClaimsWithoutUpdatingTheExistingRecord(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $repository = new DoctrineDbalIdempotencyRepository(
            $connection,
            new DefaultPrivateKeyEncryptor('test-secret'),
            86400,
            1024,
        );

        self::assertTrue($repository->claimPending('idem-3', 'fp-1'));
        self::assertFalse($repository->claimPending('idem-3', 'fp-2'));

        $loaded = $repository->find('idem-3');

        self::assertNotNull($loaded);
        self::assertSame('fp-1', $loaded->fingerprint);
        self::assertSame('pending', $loaded->status);
    }

    #[Test]
    public function itAllowsExactlyOneProcessToClaimAPendingRecord(): void
    {
        $directory = sys_get_temp_dir() . '/ucp-idempotency-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0777, true));

        $databasePath = $directory . '/idempotency.sqlite';
        $releasePath = $directory . '/release';
        $workerPath = $directory . '/claim-worker.php';
        $autoloadPath = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $firstReadyPath = $directory . '/first.ready';
        $secondReadyPath = $directory . '/second.ready';
        $firstResultPath = $directory . '/first.json';
        $secondResultPath = $directory . '/second.json';

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $databasePath,
            'driverOptions' => [
                PDO::ATTR_TIMEOUT => 5,
            ],
        ]);
        (new SchemaBootstrapper($connection))->ensureSchema();
        $connection->close();

        file_put_contents($workerPath, self::claimWorkerScript());

        $firstProcess = self::startClaimWorker($workerPath, $autoloadPath, $databasePath, $releasePath, $firstReadyPath, $firstResultPath, 'fp-1');
        $secondProcess = self::startClaimWorker($workerPath, $autoloadPath, $databasePath, $releasePath, $secondReadyPath, $secondResultPath, 'fp-2');

        try {
            self::waitForWorkerReadiness($firstProcess, $firstReadyPath);
            self::waitForWorkerReadiness($secondProcess, $secondReadyPath);

            touch($releasePath);

            self::waitForWorker($firstProcess, $firstResultPath);
            self::waitForWorker($secondProcess, $secondResultPath);

            $results = [
                self::readWorkerResult($firstResultPath),
                self::readWorkerResult($secondResultPath),
            ];

            $claimedResults = array_values(array_unique(array_column($results, 'claimed')));
            sort($claimedResults);

            self::assertSame([false, true], $claimedResults);

            $verificationConnection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $databasePath,
            ]);
            $repository = new DoctrineDbalIdempotencyRepository(
                $verificationConnection,
                new DefaultPrivateKeyEncryptor('test-secret'),
                86400,
                1024,
            );

            $loaded = $repository->find('idem-race');

            self::assertNotNull($loaded);
            self::assertSame('pending', $loaded->status);
            self::assertContains($loaded->fingerprint, array_column(array_filter($results, static fn (array $result): bool => $result['claimed']), 'fingerprint'));
        } finally {
            self::cleanupProcess($firstProcess);
            self::cleanupProcess($secondProcess);

            foreach ([$firstReadyPath, $secondReadyPath, $firstResultPath, $secondResultPath, $releasePath, $workerPath, $databasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @return resource
     */
    private static function startClaimWorker(string $workerPath, string $autoloadPath, string $databasePath, string $releasePath, string $readyPath, string $resultPath, string $fingerprint): mixed
    {
        $process = proc_open(
            [PHP_BINARY, $workerPath, $autoloadPath, $databasePath, $releasePath, $readyPath, $resultPath, $fingerprint],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        self::assertIsResource($process);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return $process;
    }

    /**
     * @param resource $process
     */
    private static function waitForWorkerReadiness(mixed $process, string $readyPath): void
    {
        $deadline = microtime(true) + 5.0;
        do {
            if (is_file($readyPath)) {
                return;
            }

            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail('Idempotency claim worker exited before reaching release barrier.');
            }

            usleep(1000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        self::fail('Timed out waiting for idempotency claim worker readiness.');
    }

    /**
     * @param resource $process
     */
    private static function waitForWorker(mixed $process, string $resultPath): void
    {
        $deadline = microtime(true) + 5.0;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::assertSame(0, $status['exitcode']);
                self::assertFileExists($resultPath);

                return;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        proc_terminate($process);
        self::fail('Timed out waiting for idempotency claim worker.');
    }

    /**
     * @param resource $process
     */
    private static function cleanupProcess(mixed $process): void
    {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    /**
     * @return array{claimed: bool, fingerprint: string}
     */
    private static function readWorkerResult(string $resultPath): array
    {
        $result = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($result);
        self::assertArrayHasKey('claimed', $result);
        self::assertArrayHasKey('fingerprint', $result);

        return [
            'claimed' => (bool) $result['claimed'],
            'fingerprint' => (string) $result['fingerprint'],
        ];
    }

    private static function claimWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Ucp\Sdk\Symfony\Bridge\DefaultStorage\DefaultPrivateKeyEncryptor;
use Ucp\Sdk\Symfony\Bridge\DoctrineDbal\DoctrineDbalIdempotencyRepository;

require $argv[1];

[$script, $autoloadPath, $databasePath, $releasePath, $readyPath, $resultPath, $fingerprint] = $argv;

$deadline = microtime(true) + 5.0;
touch($readyPath);

while (! file_exists($releasePath)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, 'Timed out waiting for release barrier.');
        exit(1);
    }

    usleep(1000);
}

$connection = DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'path' => $databasePath,
    'driverOptions' => [
        PDO::ATTR_TIMEOUT => 5,
    ],
]);
$repository = new DoctrineDbalIdempotencyRepository(
    $connection,
    new DefaultPrivateKeyEncryptor('test-secret'),
    86400,
    1024,
);

file_put_contents($resultPath, json_encode([
    'claimed' => $repository->claimPending('idem-race', $fingerprint),
    'fingerprint' => $fingerprint,
], JSON_THROW_ON_ERROR));
PHP;
    }
}
