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
