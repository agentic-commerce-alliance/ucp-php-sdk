<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Validation;

use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Service\SchemaValidatorInterface;

/** @internal */
final class GeneratedSchemaValidator implements SchemaValidatorInterface
{
    /**
     * How many of a rejected branch's own violations to quote.
     *
     * Enough to name the field, short enough that the message stays readable when a
     * branch rejects on many at once.
     */
    private const BRANCH_REASON_LIMIT = 3;

    /** @var array<string, array<string, mixed>> */
    private array $schemaCache = [];

    public function __construct(
        private string $schemaDirectory,
    ) {
    }

    public function validate(string $schemaName, array $payload): void
    {
        $schema = $this->loadSchema($schemaName);
        $violations = [];
        $this->validateValue($payload, $schema, '$', $violations, $schema);

        if ($violations !== []) {
            throw new ValidationException(sprintf('Validation failed for schema "%s".', $schemaName), $violations);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $violations
     * @param array<string, mixed> $rootSchema
     */
    private function validateValue(mixed $value, array $schema, string $path, array &$violations, array $rootSchema): void
    {
        $schema = $this->resolveSchema($schema, $rootSchema);

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $index => $subSchema) {
                if (is_array($subSchema)) {
                    $this->validateValue($value, $subSchema, $path . '.allOf[' . $index . ']', $violations, $rootSchema);
                }
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $branches = $this->validateBranches($value, $schema['anyOf'], $path, $rootSchema);

            if ($branches['matched'] === []) {
                $violations[] = sprintf('%s must match at least one allowed schema.', $path);
                foreach ($this->branchReasons($path, $branches['rejected']) as $reason) {
                    $violations[] = $reason;
                }
            }
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $branches = $this->validateBranches($value, $schema['oneOf'], $path, $rootSchema);
            $matched = $branches['matched'];

            if (count($matched) !== 1) {
                $violations[] = sprintf('%s must match exactly one allowed schema.', $path);

                if ($matched === []) {
                    foreach ($this->branchReasons($path, $branches['rejected']) as $reason) {
                        $violations[] = $reason;
                    }
                } else {
                    // Ambiguity reads nothing like absence, and the fix is the opposite:
                    // remove what makes a second branch match, rather than add what a
                    // missing one wants.
                    $violations[] = sprintf(
                        '%s matches %d allowed schemas (%s) and must match exactly one.',
                        $path,
                        count($matched),
                        implode(', ', $matched),
                    );
                }
            }
        }

        if (isset($schema['not']) && is_array($schema['not'])) {
            $candidateViolations = [];
            $this->validateValue($value, $schema['not'], $path, $candidateViolations, $rootSchema);
            if ($candidateViolations === []) {
                $violations[] = sprintf('%s must not match the disallowed schema.', $path);
            }
        }

        if (isset($schema['if']) && is_array($schema['if'])) {
            $candidateViolations = [];
            $this->validateValue($value, $schema['if'], $path, $candidateViolations, $rootSchema);
            if ($candidateViolations === [] && isset($schema['then']) && is_array($schema['then'])) {
                $this->validateValue($value, $schema['then'], $path, $violations, $rootSchema);
            }

            if ($candidateViolations !== [] && isset($schema['else']) && is_array($schema['else'])) {
                $this->validateValue($value, $schema['else'], $path, $violations, $rootSchema);
            }
        }

        if (isset($schema['const']) && ! $this->valuesEqual($value, $schema['const'])) {
            $violations[] = sprintf('%s must match the expected constant value.', $path);
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $match = false;
            foreach ($schema['enum'] as $allowedValue) {
                if ($this->valuesEqual($value, $allowedValue)) {
                    $match = true;
                    break;
                }
            }

            if (! $match) {
                $violations[] = sprintf('%s must match one of the allowed values.', $path);
            }
        }

        $expectedTypes = $this->normalizeExpectedTypes($schema['type'] ?? null);
        if ($expectedTypes !== null && ! $this->matchesExpectedTypes($value, $expectedTypes)) {
            $violations[] = sprintf('%s must be %s', $path, implode(' or ', $expectedTypes));

            return;
        }

        if (is_string($value)) {
            $this->validateString($value, $schema, $path, $violations);
        }

        if (is_int($value) || is_float($value)) {
            $this->validateNumber($value, $schema, $path, $violations);
        }

        if (! is_array($value)) {
            return;
        }

        if ($this->expectsObject($schema)) {
            $this->validateObject($value, $schema, $path, $violations, $rootSchema);
        }

        if ($this->expectsArray($schema)) {
            $this->validateArray($value, $schema, $path, $violations, $rootSchema);
        }
    }

    /**
     * @param array<int|string, mixed> $payload
     * @param array<string, mixed> $schema
     * @param list<string> $violations
     * @param array<string, mixed> $rootSchema
     */
    private function validateObject(array $payload, array $schema, string $path, array &$violations, array $rootSchema): void
    {
        if (array_is_list($payload) && $payload !== []) {
            $violations[] = sprintf('%s must be an object', $path);

            return;
        }

        foreach (($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $payload)) {
                $violations[] = sprintf('%s.%s is required', $path, $required);
            }
        }

        $count = count($payload);
        if (isset($schema['minProperties']) && is_int($schema['minProperties']) && $count < $schema['minProperties']) {
            $violations[] = sprintf('%s must contain at least %d properties', $path, $schema['minProperties']);
        }

        if (isset($schema['maxProperties']) && is_int($schema['maxProperties']) && $count > $schema['maxProperties']) {
            $violations[] = sprintf('%s must contain at most %d properties', $path, $schema['maxProperties']);
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $property => $propertySchema) {
            if (! array_key_exists((string) $property, $payload) || ! is_array($propertySchema)) {
                continue;
            }

            $value = $payload[(string) $property];
            $this->validateValue($value, $propertySchema, $path . '.' . $property, $violations, $rootSchema);
        }

        if (isset($schema['propertyNames']) && is_array($schema['propertyNames'])) {
            foreach (array_keys($payload) as $property) {
                $this->validateValue((string) $property, $schema['propertyNames'], $path . '.' . $property, $violations, $rootSchema);
            }
        }

        $additionalProperties = $schema['additionalProperties'] ?? true;
        foreach ($payload as $property => $propertyValue) {
            if (array_key_exists((string) $property, $properties)) {
                continue;
            }

            if ($additionalProperties === false) {
                $violations[] = sprintf('%s.%s is not allowed', $path, $property);
                continue;
            }

            if (is_array($additionalProperties)) {
                $this->validateValue($propertyValue, $additionalProperties, $path . '.' . $property, $violations, $rootSchema);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $value
     * @param list<string> $violations
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $rootSchema
     */
    private function validateArray(array $value, array $schema, string $path, array &$violations, array $rootSchema): void
    {
        if (! array_is_list($value)) {
            $violations[] = sprintf('%s must be an array', $path);

            return;
        }

        $count = count($value);
        if (isset($schema['minItems']) && is_int($schema['minItems']) && $count < $schema['minItems']) {
            $violations[] = sprintf('%s must contain at least %d items', $path, $schema['minItems']);
        }

        if (isset($schema['maxItems']) && is_int($schema['maxItems']) && $count > $schema['maxItems']) {
            $violations[] = sprintf('%s must contain at most %d items', $path, $schema['maxItems']);
        }

        if (isset($schema['contains']) && is_array($schema['contains'])) {
            $matches = 0;
            foreach ($value as $entry) {
                $candidateViolations = [];
                $this->validateValue($entry, $schema['contains'], $path, $candidateViolations, $rootSchema);
                if ($candidateViolations === []) {
                    ++$matches;
                }
            }

            $minContains = isset($schema['minContains']) && is_int($schema['minContains']) ? $schema['minContains'] : 1;
            if ($matches < $minContains) {
                $violations[] = sprintf('%s must contain at least %d matching items', $path, $minContains);
            }

            if (isset($schema['maxContains']) && is_int($schema['maxContains']) && $matches > $schema['maxContains']) {
                $violations[] = sprintf('%s must contain at most %d matching items', $path, $schema['maxContains']);
            }
        }

        $items = $schema['items'] ?? null;
        if (! is_array($items)) {
            return;
        }

        foreach ($value as $index => $entry) {
            $this->validateValue($entry, $items, sprintf('%s[%d]', $path, $index), $violations, $rootSchema);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $violations
     */
    private function validateString(string $value, array $schema, string $path, array &$violations): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if (isset($schema['minLength']) && is_int($schema['minLength']) && $length < $schema['minLength']) {
            $violations[] = sprintf('%s must be at least %d characters long', $path, $schema['minLength']);
        }

        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && $length > $schema['maxLength']) {
            $violations[] = sprintf('%s must be at most %d characters long', $path, $schema['maxLength']);
        }

        if (isset($schema['pattern']) && is_string($schema['pattern'])) {
            $pattern = '/' . str_replace('/', '\\/', $schema['pattern']) . '/u';
            if (@preg_match($pattern, $value) !== 1) {
                $violations[] = sprintf('%s does not match the required pattern', $path);
            }
        }

        if (! isset($schema['format']) || ! is_string($schema['format'])) {
            return;
        }

        $format = $schema['format'];
        if ($format === 'uri' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            $violations[] = sprintf('%s must be a valid URI', $path);
        }

        if ($format === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $violations[] = sprintf('%s must be a valid email address', $path);
        }

        if ($format === 'date-time') {
            try {
                new \DateTimeImmutable($value);
            } catch (\Throwable) {
                $violations[] = sprintf('%s must be a valid date-time value', $path);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $violations
     */
    private function validateNumber(int|float $value, array $schema, string $path, array &$violations): void
    {
        if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum']) {
            $violations[] = sprintf('%s must be greater than or equal to %s', $path, (string) $schema['minimum']);
        }

        if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > $schema['maximum']) {
            $violations[] = sprintf('%s must be less than or equal to %s', $path, (string) $schema['maximum']);
        }

        if (isset($schema['exclusiveMinimum']) && is_numeric($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            $violations[] = sprintf('%s must be greater than %s', $path, (string) $schema['exclusiveMinimum']);
        }

        if (isset($schema['exclusiveMaximum']) && is_numeric($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            $violations[] = sprintf('%s must be less than %s', $path, (string) $schema['exclusiveMaximum']);
        }
    }

    /**
     * Validates a value against every branch of a combinator, keeping the reasons.
     *
     * The reasons are the whole point. A branch's violations were already computed
     * here and then discarded, so `anyOf`/`oneOf` failures reported only that
     * something did not line up — never what. Two of those cost days on the same
     * project: a `destinations[]` item that matched two branches (read as "the
     * schema is unsatisfiable") and a checkout response missing one required field
     * inside one branch (read as anything but that).
     *
     * @param array<array-key, mixed> $subSchemas the combinator's branches, keyed as the schema has them
     * @param array<string, mixed> $rootSchema
     * @return array{matched: list<string>, rejected: array<string, list<string>>} branch labels, and why each rejected branch did
     */
    private function validateBranches(mixed $value, array $subSchemas, string $path, array $rootSchema): array
    {
        $matched = [];
        $rejected = [];

        foreach ($subSchemas as $index => $subSchema) {
            if (! is_array($subSchema)) {
                continue;
            }

            $label = $this->branchLabel($subSchema, $index, $rootSchema);
            $candidateViolations = [];
            $this->validateValue($value, $subSchema, $path, $candidateViolations, $rootSchema);

            if ($candidateViolations === []) {
                $matched[] = $label;

                continue;
            }

            $rejected[$label] = $candidateViolations;
        }

        return ['matched' => $matched, 'rejected' => $rejected];
    }

    /**
     * One violation per rejected branch, carrying that branch's own first reasons.
     *
     * Capped rather than exhaustive: a deep `oneOf` can reject on dozens of fields
     * per branch, and a message nobody reads to the end is the problem this is
     * fixing. The first few name the field to look at, which is what was missing.
     *
     * @param array<string, list<string>> $rejected
     * @return list<string>
     */
    private function branchReasons(string $path, array $rejected): array
    {
        $reasons = [];

        // Closest branch first: with a `oneOf` over shapes that share most of their
        // fields, the branch that rejected on one field is the one the payload was
        // trying to be, and the others are noise a reader has to skip past.
        uasort($rejected, static fn (array $left, array $right): int => count($left) <=> count($right));

        foreach ($rejected as $label => $branchViolations) {
            $shown = array_slice($branchViolations, 0, self::BRANCH_REASON_LIMIT);
            $suffix = count($branchViolations) > self::BRANCH_REASON_LIMIT
                ? sprintf(' (+%d more)', count($branchViolations) - self::BRANCH_REASON_LIMIT)
                : '';

            $reasons[] = sprintf('%s does not match %s: %s%s', $path, $label, implode('; ', $shown), $suffix);
        }

        return $reasons;
    }

    /**
     * A branch's `title` when the schema carries one, its index otherwise.
     *
     * The generated schemas do carry titles — "Checkout", "Error Response",
     * "Shipping Destination", "Retail Location" — and those names are what the
     * specification calls the shapes, so they point a reader at the right section
     * rather than at a position in an array they cannot see.
     *
     * @param array<string, mixed> $subSchema
     * @param array<string, mixed> $rootSchema
     */
    private function branchLabel(array $subSchema, int|string $index, array $rootSchema): string
    {
        $title = $this->resolveSchema($subSchema, $rootSchema)['title'] ?? null;

        return is_string($title) && $title !== ''
            ? sprintf('"%s"', $title)
            : sprintf('branch %s', (string) $index);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $rootSchema
     * @return array<string, mixed>
     */
    private function resolveSchema(array $schema, array $rootSchema): array
    {
        $reference = $schema['$ref'] ?? null;
        if (! is_string($reference) || $reference === '') {
            return $schema;
        }

        $resolved = $this->resolveReference($reference, $rootSchema);
        unset($schema['$ref']);

        return array_replace_recursive($resolved, $schema);
    }

    /**
     * @param array<string, mixed> $rootSchema
     * @return array<string, mixed>
     */
    private function resolveReference(string $reference, array $rootSchema): array
    {
        if (! str_starts_with($reference, '#/')) {
            return [];
        }

        $pointer = explode('/', substr($reference, 2));
        $value = $rootSchema;
        foreach ($pointer as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [];
            }

            $value = $value[$segment];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>|null
     */
    private function normalizeExpectedTypes(mixed $type): ?array
    {
        if (is_string($type)) {
            return [$type];
        }

        if (! is_array($type)) {
            return null;
        }

        return array_values(array_filter($type, static fn (mixed $candidate): bool => is_string($candidate)));
    }

    /**
     * @param list<string> $expectedTypes
     */
    private function matchesExpectedTypes(mixed $value, array $expectedTypes): bool
    {
        foreach ($expectedTypes as $expectedType) {
            if ($this->matchesExpectedType($value, $expectedType)) {
                return true;
            }
        }

        return false;
    }

    private function matchesExpectedType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && (! array_is_list($value) || $value === []),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function expectsObject(array $schema): bool
    {
        $types = $this->normalizeExpectedTypes($schema['type'] ?? null);
        if ($types !== null) {
            return in_array('object', $types, true);
        }

        return isset($schema['properties'])
            || isset($schema['required'])
            || isset($schema['propertyNames'])
            || isset($schema['minProperties'])
            || isset($schema['maxProperties'])
            || array_key_exists('additionalProperties', $schema);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function expectsArray(array $schema): bool
    {
        $types = $this->normalizeExpectedTypes($schema['type'] ?? null);
        if ($types !== null) {
            return in_array('array', $types, true);
        }

        return isset($schema['items'])
            || isset($schema['contains'])
            || isset($schema['minItems'])
            || isset($schema['maxItems'])
            || isset($schema['minContains'])
            || isset($schema['maxContains']);
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_THROW_ON_ERROR) === json_encode($right, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSchema(string $schemaName): array
    {
        if (isset($this->schemaCache[$schemaName])) {
            return $this->schemaCache[$schemaName];
        }

        $path = rtrim($this->schemaDirectory, '/') . '/' . $schemaName . '.json';
        if (! is_file($path)) {
            throw new ValidationException(sprintf('Schema "%s" does not exist.', $schemaName));
        }

        $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($schema)) {
            throw new ValidationException(sprintf('Schema "%s" must decode to a JSON object.', $schemaName));
        }

        return $this->schemaCache[$schemaName] = $schema;
    }
}
