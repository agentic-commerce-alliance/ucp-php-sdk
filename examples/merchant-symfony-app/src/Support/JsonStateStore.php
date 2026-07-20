<?php

declare(strict_types=1);

namespace MerchantSymfonyApp\Support;

final class JsonStateStore
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadMap(string $collection): array
    {
        $payload = $this->read($collection);
        $records = [];

        if (! is_array($payload)) {
            return [];
        }

        foreach ($payload as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $records[$key] = $value;
            }
        }

        return $records;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function loadList(string $collection): array
    {
        $payload = $this->read($collection);

        if (! is_array($payload)) {
            return [];
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $collection, string $id): ?array
    {
        return $this->loadMap($collection)[$id] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function put(string $collection, string $id, array $payload): void
    {
        $records = $this->loadMap($collection);
        $records[$id] = $payload;

        $this->write($collection, $records);
    }

    /**
     * Run a read-modify-write against a keyed collection while holding an exclusive lock, so a
     * concurrent writer cannot interleave between the read and the write. The mutator receives
     * the current records by reference, mutates them in place, and returns a value handed back
     * to the caller. This is the reference pattern for completing a checkout atomically against
     * a verified AP2 mandate snapshot (compare-and-set on the checkout's terms fingerprint).
     *
     * @param callable(array<string, array<string, mixed>>): mixed $mutator receives records by reference
     */
    public function mutate(string $collection, callable $mutator): mixed
    {
        $path = $this->path($collection);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to open state file "%s".', $path));
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock state file "%s".', $path));
            }

            $contents = stream_get_contents($handle);
            $decoded = ($contents === false || $contents === '')
                ? []
                : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            $records = [];
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && is_array($value)) {
                        $records[$key] = $value;
                    }
                }
            }

            $result = $mutator($records);

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($records, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            fflush($handle);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function remove(string $collection, string $id): void
    {
        $records = $this->loadMap($collection);
        unset($records[$id]);

        $this->write($collection, $records);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function append(string $collection, array $payload): void
    {
        $records = $this->loadList($collection);
        $records[] = $payload;

        $this->write($collection, $records);
    }

    public function clear(string $collection): void
    {
        @unlink($this->path($collection));
    }

    /**
     * @return mixed
     */
    private function read(string $collection): mixed
    {
        $path = $this->path($collection);

        if (! file_exists($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $payload
     */
    private function write(string $collection, array $payload): void
    {
        $directory = dirname($this->path($collection));
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path($collection), json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    }

    private function path(string $collection): string
    {
        return $this->projectDir . '/var/state/' . $collection . '.json';
    }
}
