<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Service;

use Ucp\Sdk\Exception\IdempotencyConflictException;
use Ucp\Sdk\Model\IdempotencyRecord;
use Ucp\Sdk\Repository\IdempotencyRepositoryInterface;
use Ucp\Sdk\Service\IdempotencyServiceInterface;

/** @internal */
final class DefaultIdempotencyService implements IdempotencyServiceInterface
{
    public function __construct(
        private readonly IdempotencyRepositoryInterface $repository,
    ) {
    }

    public function claim(string $key, string $fingerprint): IdempotencyRecord
    {
        if ($this->repository->claimPending($key, $fingerprint)) {
            return new IdempotencyRecord($key, $fingerprint);
        }

        $record = $this->repository->find($key);

        if ($record === null) {
            throw new IdempotencyConflictException('Idempotency key is already being processed.');
        }

        if ($record->fingerprint !== $fingerprint) {
            throw new IdempotencyConflictException('Idempotency key reused with a different request fingerprint.');
        }

        if ($record->status === 'pending') {
            throw new IdempotencyConflictException('Idempotency key is already being processed.');
        }

        return $record;
    }

    public function complete(IdempotencyRecord $record, array $responseBody, int $statusCode, bool $replayable = true): void
    {
        $this->repository->save(new IdempotencyRecord(
            $record->key,
            $record->fingerprint,
            'completed',
            $responseBody,
            $statusCode,
            $replayable,
        ));
    }

    public function abort(IdempotencyRecord $record): void
    {
        $this->repository->delete($record->key);
    }
}
