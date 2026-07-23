<?php

declare(strict_types=1);

/**
 * @param list<string> $argv
 */
function main(array $argv): void
{
    $version = $argv[1] ?? '2026-04-08';
    $source = $argv[2] ?? getenv('UCP_SOURCE_DIR') ?: null;

    if (! is_string($source) || $source === '') {
        fail('Usage: php tools/sync-ucp-schemas.php <version> <path-to-ucp-source>');
    }

    $source = rtrim($source, '/');
    $schemaRoot = $source . '/source/schemas';
    if (! is_dir($schemaRoot)) {
        fail(sprintf('UCP schema source directory "%s" does not exist.', $schemaRoot));
    }

    $repoRoot = dirname(__DIR__);
    $pinnedRoot = $repoRoot . '/packages/core/resources/schema/pinned/' . $version;
    $generatedRoot = $repoRoot . '/packages/core/resources/schema/generated/' . $version;

    mirrorDirectory($schemaRoot, $pinnedRoot . '/schemas');
    mirrorDirectory($source . '/source/discovery', $pinnedRoot . '/discovery');
    mirrorDirectory($source . '/source/services', $pinnedRoot . '/services');
    mirrorDirectory($source . '/source/handlers', $pinnedRoot . '/handlers');
    resetDirectory($generatedRoot);

    $generator = new SchemaGenerator($schemaRoot);
    foreach (operationSchemas($schemaRoot) as $filename => $schema) {
        $generated = $generator->generate($schema);
        if ($filename === 'checkout.create.request') {
            $generated = allowCartIdInsteadOfLineItems($generated, $schemaRoot);
        }

        writeJson($generatedRoot . '/' . $filename . '.json', $generated);
    }
}

/**
 * checkout.create accepts a cart_id (from the cart capability) as an alternative to line_items.
 *
 * Per shopping/cart.json, when cart_id is supplied the business uses the cart's contents
 * (line_items, context, buyer) and ignores overlapping fields in the checkout payload — so
 * requiring line_items in that case is contradictory. Reflect the true contract: the request
 * carries cart_id, and either line_items or cart_id must be present.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function allowCartIdInsteadOfLineItems(array $schema, string $schemaRoot): array
{
    if (! isset($schema['properties']) || ! is_array($schema['properties'])) {
        return $schema;
    }

    $schema['properties']['cart_id'] = cartIdPropertySchema($schemaRoot);

    // line_items is no longer unconditionally required; either line_items or cart_id must be present.
    unset($schema['required']);
    $schema['anyOf'] = [
        ['required' => ['line_items']],
        ['required' => ['cart_id']],
    ];

    return $schema;
}

/**
 * Reads the cart_id property definition from the cart capability (shopping/cart.json), keeping the
 * spec as the single source of truth for its shape and description. The ucp_request projection
 * annotation is dropped — it is a generation hint, not part of the emitted JSON Schema.
 *
 * @return array<string, mixed>
 */
function cartIdPropertySchema(string $schemaRoot): array
{
    $cart = json_decode((string) file_get_contents($schemaRoot . '/shopping/cart.json'), true, 512, JSON_THROW_ON_ERROR);
    $variants = (is_array($cart) ? $cart['$defs']['checkout']['allOf'] ?? [] : []);
    if (is_array($variants)) {
        foreach ($variants as $variant) {
            if (is_array($variant) && isset($variant['properties']['cart_id']) && is_array($variant['properties']['cart_id'])) {
                $cartId = $variant['properties']['cart_id'];
                unset($cartId['ucp_request']);

                return $cartId;
            }
        }
    }

    fail('cart_id property not found in shopping/cart.json ($defs.checkout).');
}

/**
 * @return array<string, array{file: string, pointer?: string, request?: string, oneOf?: list<array{file: string, pointer?: string}>}>
 */
function operationSchemas(string $schemaRoot): array
{
    return [
        'catalog.search.request' => ['file' => 'shopping/catalog_search.json', 'pointer' => '/$defs/search_request'],
        'catalog.search.response' => ['file' => 'shopping/catalog_search.json', 'pointer' => '/$defs/search_response'],
        'catalog.lookup.request' => ['file' => 'shopping/catalog_lookup.json', 'pointer' => '/$defs/lookup_request'],
        'catalog.lookup.response' => ['file' => 'shopping/catalog_lookup.json', 'pointer' => '/$defs/lookup_response'],
        'catalog.product.request' => ['file' => 'shopping/catalog_lookup.json', 'pointer' => '/$defs/get_product_request'],
        'catalog.product.response' => ['oneOf' => [
            ['file' => 'shopping/catalog_lookup.json', 'pointer' => '/$defs/get_product_response'],
            ['file' => 'shopping/types/error_response.json'],
        ]],
        'cart.create.request' => ['file' => 'shopping/cart.json', 'request' => 'create'],
        'cart.create.response' => responseWithError('shopping/cart.json'),
        'cart.get.request' => idRequest(),
        'cart.get.response' => responseWithError('shopping/cart.json'),
        'cart.update.request' => ['file' => 'shopping/cart.json', 'request' => 'update'],
        'cart.update.response' => responseWithError('shopping/cart.json'),
        'cart.cancel.request' => idRequest(),
        'cart.cancel.response' => responseWithError('shopping/cart.json'),
        'discount.apply.request' => [
            'type' => 'object',
            'required' => ['cart_id', 'code'],
            'properties' => [
                'cart_id' => ['type' => 'string'],
                'code' => ['type' => 'string'],
            ],
        ],
        'discount.apply.response' => responseWithError('shopping/cart.json'),
        'checkout.create.request' => ['file' => 'shopping/checkout.json', 'request' => 'create'],
        'checkout.create.response' => responseWithError('shopping/checkout.json'),
        'checkout.get.request' => idRequest(),
        'checkout.get.response' => responseWithError('shopping/checkout.json'),
        'checkout.update.request' => ['file' => 'shopping/checkout.json', 'request' => 'update'],
        'checkout.update.response' => responseWithError('shopping/checkout.json'),
        'checkout.complete.request' => ['file' => 'shopping/checkout.json', 'request' => 'complete'],
        'checkout.complete.response' => responseWithError('shopping/checkout.json'),
        'checkout.cancel.request' => idRequest(),
        'checkout.cancel.response' => responseWithError('shopping/checkout.json'),
        'order.get.request' => idRequest(),
        'order.get.response' => responseWithError('shopping/order.json'),
        'tokenization.request' => ['file' => '../handlers/tokenization/openapi.json', 'pointer' => '/paths/~1tokenize/post/requestBody/content/application~1json/schema'],
        'tokenization.response' => ['file' => '../handlers/tokenization/openapi.json', 'pointer' => '/paths/~1tokenize/post/responses/200/content/application~1json/schema'],
    ];
}

/**
 * @return array{oneOf: list<array{file: string}>}
 */
function responseWithError(string $file): array
{
    return ['oneOf' => [
        ['file' => $file],
        ['file' => 'shopping/types/error_response.json'],
    ]];
}

/**
 * @return array<string, mixed>
 */
function idRequest(): array
{
    return [
        'type' => 'object',
        'required' => ['id'],
        'properties' => [
            'id' => ['type' => 'string'],
        ],
        'additionalProperties' => false,
    ];
}

final class SchemaGenerator
{
    /** @var array<string, array<string, mixed>> */
    private array $documents = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function __construct(
        private readonly string $schemaRoot,
    ) {
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public function generate(array $operation): array
    {
        $schema = isset($operation['oneOf']) && is_array($operation['oneOf'])
            ? ['oneOf' => array_map(fn (array $entry): array => $this->schemaFromOperation($entry), $operation['oneOf'])]
            : $this->schemaFromOperation($operation);

        $schema = $this->stripAnnotations($schema);
        if (isset($schema['$schema'])) {
            unset($schema['$schema'], $schema['$id'], $schema['name'], $schema['title'], $schema['description']);
        }

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            ...$schema,
        ];
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function schemaFromOperation(array $operation): array
    {
        if (! isset($operation['file'])) {
            return $operation;
        }

        $file = $this->absolutePath((string) $operation['file']);
        $schema = $this->readPointer($file, (string) ($operation['pointer'] ?? ''));

        if (isset($operation['request']) && is_string($operation['request'])) {
            return $this->projectRequestSchema($schema, $file, $operation['request']);
        }

        return $this->dereference($schema, $file);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function projectRequestSchema(array $schema, string $file, string $operation): array
    {
        $schema = $this->resolveReferenceSchema($schema, $file);

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $merged = ['type' => 'object', 'properties' => [], 'required' => []];
            foreach ($schema['allOf'] as $subSchema) {
                if (! is_array($subSchema)) {
                    continue;
                }

                $projected = $this->projectRequestSchema($subSchema, $file, $operation);
                $merged['properties'] = [
                    ...($merged['properties'] ?? []),
                    ...($projected['properties'] ?? []),
                ];
                $merged['required'] = array_values(array_unique([
                    ...($merged['required'] ?? []),
                    ...($projected['required'] ?? []),
                ]));
            }

            if ($merged['required'] === []) {
                unset($merged['required']);
            }

            return $merged;
        }

        if (! isset($schema['properties']) || ! is_array($schema['properties'])) {
            return $this->dereference($schema, $file);
        }

        $hasRequestAnnotations = false;
        foreach ($schema['properties'] as $propertySchema) {
            if (is_array($propertySchema) && array_key_exists('ucp_request', $propertySchema)) {
                $hasRequestAnnotations = true;
                break;
            }
        }

        if (! $hasRequestAnnotations) {
            return $this->dereference($schema, $file);
        }

        $projected = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        $requiredProperties = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($schema['properties'] as $property => $propertySchema) {
            if (! is_string($property) || ! is_array($propertySchema)) {
                continue;
            }

            $requestState = $this->requestState($propertySchema['ucp_request'] ?? null, $operation, in_array($property, $requiredProperties, true));
            if ($requestState === 'omit') {
                continue;
            }

            $projected['properties'][$property] = $this->projectNestedRequestProperty($propertySchema, $file, $operation);
            if ($requestState === 'required') {
                $projected['required'][] = $property;
            }
        }

        if ($projected['required'] === []) {
            unset($projected['required']);
        }

        return $projected;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function projectNestedRequestProperty(array $schema, string $file, string $operation): array
    {
        $schema = $this->resolveReferenceSchema($schema, $file);
        if (isset($schema['properties']) || isset($schema['allOf'])) {
            return $this->projectRequestSchema($schema, $file, $operation);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->projectNestedRequestProperty($schema['items'], $file, $operation);

            return $this->dereference($schema, $file);
        }

        return $this->dereference($schema, $file);
    }

    private function requestState(mixed $annotation, string $operation, bool $required): string
    {
        if (is_string($annotation)) {
            return $annotation;
        }

        if (is_array($annotation) && isset($annotation[$operation]) && is_string($annotation[$operation])) {
            return $annotation[$operation];
        }

        return $required ? 'required' : 'optional';
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function dereference(array $schema, string $file): array
    {
        $schema = $this->resolveReferenceSchema($schema, $file);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->isList($value)
                    ? array_map(fn (mixed $entry): mixed => is_array($entry) ? $this->dereference($entry, $file) : $entry, $value)
                    : $this->dereference($value, $file);
            }
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function resolveReferenceSchema(array $schema, string $file): array
    {
        $reference = $schema['$ref'] ?? null;
        if (! is_string($reference) || $reference === '') {
            return $schema;
        }

        [$referenceFile, $pointer] = $this->resolveReference($reference, $file);
        $key = $referenceFile . '#' . $pointer;
        if (isset($this->resolving[$key])) {
            return [
                'type' => ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'],
            ];
        }

        $this->resolving[$key] = true;
        $resolved = $this->readPointer($referenceFile, $pointer);
        $resolved = $this->dereference($resolved, $referenceFile);
        unset($this->resolving[$key], $schema['$ref']);

        return array_replace_recursive($resolved, $schema);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveReference(string $reference, string $file): array
    {
        if (str_starts_with($reference, '#')) {
            return [$file, substr($reference, 1)];
        }

        [$path, $pointer] = array_pad(explode('#', $reference, 2), 2, '');
        $referenceFile = realpath(dirname($file) . '/' . $path);
        if ($referenceFile === false) {
            fail(sprintf('Unable to resolve schema reference "%s" from "%s".', $reference, $file));
        }

        return [$referenceFile, $pointer];
    }

    /**
     * @return array<string, mixed>
     */
    private function readPointer(string $file, string $pointer): array
    {
        $value = $this->readDocument($file);
        if ($pointer !== '') {
            foreach (explode('/', ltrim($pointer, '/')) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    fail(sprintf('Pointer "%s" does not exist in "%s".', $pointer, $file));
                }

                $value = $value[$segment];
            }
        }

        if (! is_array($value) || $this->isList($value)) {
            fail(sprintf('Pointer "%s" in "%s" does not resolve to a JSON object.', $pointer, $file));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function readDocument(string $file): array
    {
        if (isset($this->documents[$file])) {
            return $this->documents[$file];
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            fail(sprintf('Unable to read schema file "%s".', $file));
        }

        $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document) || $this->isList($document)) {
            fail(sprintf('Schema file "%s" must contain a JSON object.', $file));
        }

        return $this->documents[$file] = $document;
    }

    private function absolutePath(string $file): string
    {
        $path = realpath($this->schemaRoot . '/' . $file);
        if ($path === false) {
            fail(sprintf('Schema file "%s" does not exist.', $file));
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function stripAnnotations(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'ucp_')) {
                unset($schema[$key]);
                continue;
            }

            if (is_array($value)) {
                $schema[$key] = $this->isList($value)
                    ? array_map(fn (mixed $entry): mixed => is_array($entry) ? $this->stripAnnotations($entry) : $entry, $value)
                    : $this->stripAnnotations($value);
            }
        }

        return $schema;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}

function mirrorDirectory(string $source, string $target): void
{
    if (! is_dir($source)) {
        return;
    }

    resetDirectory($target);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($entry->isDir()) {
            if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
                fail(sprintf('Unable to create directory "%s".', $destination));
            }
            continue;
        }

        if (! copy($entry->getPathname(), $destination)) {
            fail(sprintf('Unable to copy "%s" to "%s".', $entry->getPathname(), $destination));
        }
    }
}

function resetDirectory(string $directory): void
{
    if (is_dir($directory)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
                continue;
            }

            unlink($entry->getPathname());
        }
    }

    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fail(sprintf('Unable to create directory "%s".', $directory));
    }
}

/**
 * @param array<string, mixed> $payload
 */
function writeJson(string $file, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($file, $json) === false) {
        fail(sprintf('Unable to write "%s".', $file));
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

main($argv);
