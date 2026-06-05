<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\ValidationException;
use Ucp\Sdk\Internal\Validation\GeneratedSchemaValidator;

final class GeneratedSchemaValidatorTest extends TestCase
{
    /** @var list<string> */
    private array $temporarySchemaDirectories = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->temporarySchemaDirectories as $directory) {
            @unlink($directory . '/custom.json');
            @rmdir($directory);
        }
    }

    public function testItValidatesRequiredFields(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');
        $validator->validate('catalog.search.request', ['query' => 'shoes']);

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsMissingRequiredFields(): void
    {
        $validator = new GeneratedSchemaValidator(dirname(__DIR__, 2) . '/resources/schema/generated/2026-04-08');

        $this->expectException(ValidationException::class);
        $validator->validate('catalog.search.request', []);
    }

    public function testItValidatesAdditionalSchemaKeywords(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'type' => 'object',
            'required' => ['status', 'items'],
            'additionalProperties' => false,
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok', 'pending']],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['sku'],
                        'properties' => [
                            'sku' => ['type' => 'string', 'pattern' => '^[A-Z0-9-]+$'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);
        $validator->validate('custom', [
            'status' => 'ok',
            'items' => [
                ['sku' => 'SKU-1'],
            ],
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsEnumPatternAndAdditionalPropertyViolations(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'type' => 'object',
            'required' => ['status'],
            'additionalProperties' => false,
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok']],
                'code' => ['type' => 'string', 'pattern' => '^ABC$'],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);

        $this->expectException(ValidationException::class);
        $validator->validate('custom', [
            'status' => 'bad',
            'code' => 'XYZ',
            'unexpected' => true,
        ]);
    }

    public function testItValidatesOneOfAndStringFormats(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['callback'],
                    'properties' => [
                        'callback' => ['type' => 'string', 'format' => 'uri'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);
        $validator->validate('custom', ['callback' => 'https://example.test/callback']);

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsValuesThatDoNotMatchOneOfVariants(): void
    {
        $directory = $this->createTemporarySchemaDirectory();
        file_put_contents($directory . '/custom.json', json_encode([
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['count'],
                    'properties' => [
                        'count' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $validator = new GeneratedSchemaValidator($directory);

        $this->expectException(ValidationException::class);
        $validator->validate('custom', ['email' => 'not-an-email']);
    }

    private function createTemporarySchemaDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/ucp-sdk-schema-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $this->temporarySchemaDirectories[] = $directory;

        return $directory;
    }
}
