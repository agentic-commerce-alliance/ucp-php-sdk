<?php

declare(strict_types=1);

const USAGE = <<<'TXT'
Usage:
  php tools/sync-ucp-schemas.php <version> <path-to-ucp-source>   sync from an upstream checkout
  php tools/sync-ucp-schemas.php --verify <version>               regenerate from the pinned copy and diff

The version is required. It used to default to a hardcoded one, which meant omitting it
regenerated a version the operator had not asked for.
TXT;

/**
 * @param list<string> $argv
 */
function main(array $argv): void
{
    $arguments = [];
    $verify = false;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--verify') {
            $verify = true;

            continue;
        }

        if (str_starts_with($argument, '--')) {
            fail(sprintf("Unknown option \"%s\".\n\n%s", $argument, USAGE));
        }

        $arguments[] = $argument;
    }

    $repoRoot = dirname(__DIR__);
    $schemaBase = $repoRoot . '/packages/core/resources/schema';
    $version = $arguments[0] ?? '';

    // --verify with no version checks every pinned version. Naming one in composer.json would
    // put the protocol version back in a place that has to be remembered on a bump; discovering
    // them means a newly pinned version is covered the moment it lands.
    if ($verify) {
        foreach ($version === '' ? generatedVersions($schemaBase) : [$version] as $target) {
            assertVersionShape($target);
            verify($target, $schemaBase . '/pinned/' . $target, $schemaBase . '/generated/' . $target);
        }

        return;
    }

    assertVersionShape($version);
    $pinnedRoot = $schemaBase . '/pinned/' . $version;
    $generatedRoot = $schemaBase . '/generated/' . $version;

    $source = $arguments[1] ?? (getenv('UCP_SOURCE_DIR') ?: null);
    if (! is_string($source) || $source === '') {
        fail(USAGE);
    }

    $source = rtrim($source, '/');
    $schemaRoot = $source . '/source/schemas';
    if (! is_dir($schemaRoot)) {
        fail(sprintf('UCP schema source directory "%s" does not exist.', $schemaRoot));
    }

    mirrorDirectory($schemaRoot, $pinnedRoot . '/schemas');
    mirrorDirectory($source . '/source/discovery', $pinnedRoot . '/discovery');
    mirrorDirectory($source . '/source/services', $pinnedRoot . '/services');
    mirrorDirectory($source . '/source/handlers', $pinnedRoot . '/handlers');
    resetDirectory($generatedRoot);

    [$documents, $placeholders] = generateAll($schemaRoot);
    foreach ($documents as $filename => $json) {
        if (file_put_contents($generatedRoot . '/' . $filename . '.json', $json) === false) {
            fail(sprintf('Unable to write "%s".', $generatedRoot . '/' . $filename . '.json'));
        }
    }

    assertPlaceholderBudget($repoRoot, $version, $placeholders);
    printf("Synced %d generated schemas for %s.\n", count($documents), $version);
}

function assertVersionShape(string $version): void
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) !== 1) {
        fail(sprintf("Expected a version in YYYY-MM-DD form, got \"%s\".\n\n%s", $version, USAGE));
    }
}

/**
 * @return list<string>
 */
function generatedVersions(string $schemaBase): array
{
    $versions = [];
    foreach ((array) glob($schemaBase . '/generated/*', GLOB_ONLYDIR) as $directory) {
        $versions[] = basename((string) $directory);
    }

    if ($versions === []) {
        fail(sprintf('No generated schema sets found under "%s".', $schemaBase . '/generated'));
    }

    sort($versions);

    return $versions;
}

/**
 * Regenerates from the pinned copy and diffs against what is committed.
 *
 * The pinned tree *is* the upstream `source/` tree, so the generated set is reproducible from
 * it with no network access -- which is what lets this run inside `composer qa`. It catches two
 * things nothing else did: a generated file edited by hand, and an upstream retag that changed
 * the pinned inputs without changing their paths.
 */
function verify(string $version, string $pinnedRoot, string $generatedRoot): void
{
    $schemaRoot = $pinnedRoot . '/schemas';
    foreach ([$schemaRoot, $generatedRoot] as $required) {
        if (! is_dir($required)) {
            fail(sprintf('Cannot verify %s: "%s" does not exist.', $version, $required));
        }
    }

    [$documents, $placeholders] = generateAll($schemaRoot);

    $committed = [];
    foreach ((array) glob($generatedRoot . '/*.json') as $file) {
        $committed[basename((string) $file, '.json')] = (string) file_get_contents((string) $file);
    }

    $problems = [];
    foreach ($documents as $filename => $json) {
        if (! array_key_exists($filename, $committed)) {
            $problems[] = sprintf('%s.json is missing from %s', $filename, $generatedRoot);

            continue;
        }

        if ($committed[$filename] !== $json) {
            $problems[] = sprintf('%s.json differs from what the generator produces', $filename);
        }
    }

    foreach (array_diff(array_keys($committed), array_keys($documents)) as $orphan) {
        $problems[] = sprintf('%s.json is committed but no operation produces it', $orphan);
    }

    if ($problems !== []) {
        fail(sprintf(
            "Generated schemas for %s are not reproducible from the pinned copy:\n- %s\n\n"
            . 'Re-run the sync against the pinned tag rather than editing generated files by hand.',
            $version,
            implode("\n- ", $problems),
        ));
    }

    assertPlaceholderBudget(dirname(__DIR__), $version, $placeholders);
    printf("Generated schemas for %s are reproducible from the pinned copy (%d files).\n", $version, count($documents));
}

/**
 * @return array{0: array<string, string>, 1: list<string>}
 */
function generateAll(string $schemaRoot): array
{
    $generator = new SchemaGenerator($schemaRoot);
    $documents = [];
    foreach (operationSchemas($schemaRoot) as $filename => $schema) {
        $generated = $generator->generate($schema);
        if ($filename === 'checkout.create.request') {
            $generated = allowCartIdInsteadOfLineItems($generated);
        }

        $documents[$filename] = encodeJson($generated);
    }

    return [$documents, $generator->cyclePlaceholders()];
}

/**
 * Fails when flattening cut more recursive $refs than the recorded budget allows.
 *
 * Every placeholder is a subtree that validates as any type, so the count is a direct measure
 * of how much of the contract is unenforced. Without a recorded number, a restructured upstream
 * tree can double it and nothing says so.
 *
 * @param list<string> $placeholders
 */
function assertPlaceholderBudget(string $repoRoot, string $version, array $placeholders): void
{
    $file = $repoRoot . '/tools/sync-cycle-placeholder-budget.json';
    $budgets = is_file($file)
        ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)
        : [];
    if (! is_array($budgets)) {
        fail(sprintf('"%s" must contain a JSON object of version => budget.', $file));
    }

    $count = count($placeholders);
    if (! array_key_exists($version, $budgets)) {
        fail(sprintf(
            "No cycle-placeholder budget recorded for %s. Flattening cut %d recursive \$ref(s):\n- %s\n\n"
            . 'Record it as {"%s": %d} in %s once you have looked at the list.',
            $version,
            $count,
            implode("\n- ", $placeholders) ?: '(none)',
            $version,
            $count,
            $file,
        ));
    }

    $budget = $budgets[$version];
    if (! is_int($budget)) {
        fail(sprintf('Budget for %s in "%s" must be an integer.', $version, $file));
    }

    if ($count > $budget) {
        fail(sprintf(
            "Flattening cut %d recursive \$ref(s) for %s, above the recorded budget of %d:\n- %s\n\n"
            . 'Each one is a subtree that validates as any type. Either flatten them or raise the '
            . 'budget deliberately, with a note saying what stopped being validated.',
            $count,
            $version,
            $budget,
            implode("\n- ", $placeholders),
        ));
    }
}

/**
 * checkout.create accepts a cart_id (from the cart capability) as an alternative to line_items.
 *
 * The property itself arrives through the ordinary extension projection -- checkout.create lists
 * shopping/cart.json's /$defs/checkout among its extensions -- so only the requirement needs
 * adjusting here. The spec contradicts itself on that point: checkout.json marks line_items
 * `ucp_request.create: required`, while cart.json says that when cart_id is supplied the business
 * MUST use the cart's contents (line_items, context, buyer) and MUST ignore overlapping fields in
 * the checkout payload. Requiring line_items in that case is therefore unsatisfiable for the
 * cart-to-checkout conversion the cart capability exists to express. Emit the reachable contract:
 * either line_items or cart_id must be present.
 *
 * @param array<string, mixed> $schema
 * @return array<string, mixed>
 */
function allowCartIdInsteadOfLineItems(array $schema): array
{
    if (! isset($schema['properties']) || ! is_array($schema['properties'])) {
        return $schema;
    }

    if (! isset($schema['properties']['cart_id'])) {
        fail('checkout.create.request has no cart_id property; the cart.json extension projection changed.');
    }

    // line_items is no longer unconditionally required; either line_items or cart_id must be present.
    unset($schema['required']);
    $schema['anyOf'] = [
        ['required' => ['line_items']],
        ['required' => ['cart_id']],
    ];

    return $schema;
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
        'checkout.create.request' => [
            'file' => 'shopping/checkout.json',
            'request' => 'create',
            // cart_id is create-only: it is what converts an existing cart into a
            // checkout, and there is nothing to convert on update or complete.
            'extensions' => [...checkoutExtensions(), ['file' => 'shopping/cart.json', 'pointer' => '/$defs/checkout']],
        ],
        'checkout.create.response' => responseWithError('shopping/checkout.json'),
        'checkout.get.request' => idRequest(),
        'checkout.get.response' => responseWithError('shopping/checkout.json'),
        'checkout.update.request' => [
            'file' => 'shopping/checkout.json',
            'request' => 'update',
            'extensions' => checkoutExtensions(),
        ],
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
 * Capability extensions that compose onto checkout for create and update.
 *
 * Only the ones HttpPayloadMapper actually consumes, so the published contract
 * describes what the SDK acts on rather than everything the spec could compose.
 * `ap2_mandate.json` is deliberately absent: mandates travel through
 * PaymentMandateVerifierInterface, not through the checkout request payload.
 *
 * @return list<array{file: string, pointer: string}>
 */
function checkoutExtensions(): array
{
    return [
        ['file' => 'shopping/discount.json', 'pointer' => '/$defs/dev.ucp.shopping.checkout'],
        ['file' => 'shopping/fulfillment.json', 'pointer' => '/$defs/dev.ucp.shopping.checkout'],
        // Extends `buyer` with consent tracking rather than adding a sibling field.
        ['file' => 'shopping/buyer_consent.json', 'pointer' => '/$defs/dev.ucp.shopping.checkout'],
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

    /** @var list<string> */
    private array $cyclePlaceholders = [];

    public function __construct(
        private readonly string $schemaRoot,
    ) {
    }

    /**
     * @return list<string>
     */
    public function cyclePlaceholders(): array
    {
        return array_values(array_unique($this->cyclePlaceholders));
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
            return $this->withExtensions(
                $this->projectRequestSchema($schema, $file, $operation['request']),
                $operation,
                $operation['request'],
            );
        }

        return $this->dereference($schema, $file);
    }

    /**
     * Folds capability extensions into a projected request schema.
     *
     * Capabilities compose onto a base schema through `allOf` -- `cart.json` adds
     * `cart_id` to checkout, `discount.json` adds `discounts`, and so on -- and each
     * extension lives in its own file with its own `$defs` entry. Pointing an
     * operation at the base schema alone therefore published a request contract that
     * omitted every extension field, even though HttpPayloadMapper reads them
     * unconditionally. Callers had to know the fields existed from the spec text.
     *
     * Each extension is projected against the same operation, so its own
     * `ucp_request` annotations still decide whether a field is required, optional or
     * omitted for create/update/complete. Extensions only ever add optional fields,
     * so nothing that validated before stops validating.
     *
     * @param array<string, mixed> $projected
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function withExtensions(array $projected, array $operation, string $request): array
    {
        if (! isset($operation['extensions']) || ! is_array($operation['extensions'])) {
            return $projected;
        }

        foreach ($operation['extensions'] as $extension) {
            if (! is_array($extension) || ! isset($extension['file'])) {
                continue;
            }

            $file = $this->absolutePath((string) $extension['file']);
            $schema = $this->projectRequestSchema(
                $this->readPointer($file, (string) ($extension['pointer'] ?? '')),
                $file,
                $request,
            );

            $projected['properties'] = $this->mergeProperties(
                is_array($projected['properties'] ?? null) ? $projected['properties'] : [],
                is_array($schema['properties'] ?? null) ? $schema['properties'] : [],
            );

            $required = array_values(array_unique([
                ...($projected['required'] ?? []),
                ...($schema['required'] ?? []),
            ]));
            if ($required !== []) {
                $projected['required'] = $required;
            }
        }

        return $projected;
    }

    /**
     * Unions two property maps, descending into objects both sides describe.
     *
     * Every extension projection restates the base properties alongside the one it
     * adds, so a plain overwrite would let whichever extension ran last erase what
     * the others contributed -- `buyer_consent` adds `consent` to `buyer`, and any
     * extension merged afterwards restates the plain `buyer` and would drop it.
     * Descending instead makes the result independent of extension order.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $extension
     * @return array<string, mixed>
     */
    private function mergeProperties(array $base, array $extension): array
    {
        foreach ($extension as $name => $schema) {
            if (! array_key_exists($name, $base)) {
                $base[$name] = $schema;

                continue;
            }

            if (
                ! is_array($base[$name])
                || ! is_array($schema)
                || ! is_array($base[$name]['properties'] ?? null)
                || ! is_array($schema['properties'] ?? null)
            ) {
                $base[$name] = $schema;

                continue;
            }

            $merged = $schema;
            $merged['properties'] = $this->mergeProperties($base[$name]['properties'], $schema['properties']);

            $required = array_values(array_unique([
                ...(is_array($base[$name]['required'] ?? null) ? $base[$name]['required'] : []),
                ...(is_array($schema['required'] ?? null) ? $schema['required'] : []),
            ]));
            if ($required !== []) {
                $merged['required'] = $required;
            }

            $base[$name] = $merged;
        }

        return $base;
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
            // A recursive $ref cannot be flattened, so the cycle is cut with a schema that
            // accepts every type -- i.e. that subtree stops being validated at all. That is a
            // deliberate trade, but it is silent, and the 2026-08-25 type graph is markedly more
            // recursive (location, geo, policy, constraint_expression, payment_schedule). Counting
            // them turns "validation quietly got looser" into a number a gate can compare.
            $this->cyclePlaceholders[] = $key;

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

function mirrorDirectory(string $source, string $target, bool $required = true): void
{
    if (! is_dir($source)) {
        if ($required) {
            fail(sprintf(
                'Expected upstream directory "%s" does not exist. If the spec moved or removed it, '
                . 'update the mirror list in main() rather than letting the previous version\'s pinned '
                . 'copy survive untouched.',
                $source,
            ));
        }

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
 * The one encoder, so --verify compares bytes against the same formatting the sync wrote.
 *
 * @param array<string, mixed> $payload
 */
function encodeJson(array $payload): string
{
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/**
 * @param array<string, mixed> $payload
 */
function writeJson(string $file, array $payload): void
{
    if (file_put_contents($file, encodeJson($payload)) === false) {
        fail(sprintf('Unable to write "%s".', $file));
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

main($argv);
