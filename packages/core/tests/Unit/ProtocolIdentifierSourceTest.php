<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\UcpCapability;
use Ucp\Sdk\Enum\UcpProtocolVersion;

/**
 * Keeps protocol identifiers in one place, by failing when a second place appears.
 *
 * The version was hardcoded in three files that each looked self-consistent, and nothing noticed
 * they disagreed until a bump moved one of them. That is the failure this guards: not the
 * mistake, which was easy to fix, but the year it survived because no test could see it.
 *
 * Test data is deliberately out of scope. A fixture asserting against `current()` asserts
 * nothing -- it would agree with whatever the enum said, including a wrong value.
 */
final class ProtocolIdentifierSourceTest extends TestCase
{
    #[Test]
    public function noProductionSourceCarriesAProtocolVersionLiteral(): void
    {
        $offenders = [];

        foreach ($this->productionSources() as $path) {
            // The enum is where these belong; it is the one file allowed to spell them out.
            if (str_ends_with($path, 'Enum/UcpProtocolVersion.php')) {
                continue;
            }

            $withoutComments = $this->stripComments((string) file_get_contents($path));
            if (preg_match('/\b20\d{2}-\d{2}-\d{2}\b/', $withoutComments) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Protocol version literals belong in UcpProtocolVersion.\n" . implode("\n", $offenders),
        );
    }

    #[Test]
    public function everyCapabilityIdTheExamplesPublishIsKnownToTheEnum(): void
    {
        // The examples are what adopters copy, so an id they publish that the SDK does not know
        // is an id negotiation cannot match on -- silently, because matching is by string.
        $known = array_map(static fn (UcpCapability $case): string => $case->value, UcpCapability::cases());
        $unknown = [];

        foreach ($this->exampleSources() as $path) {
            preg_match_all("/'(dev\\.ucp\\.[a-z_.]+)'/", (string) file_get_contents($path), $matches);
            foreach ($matches[1] as $id) {
                if (! in_array($id, $known, true) && ! in_array($id, self::NOT_IN_THE_SPEC, true)) {
                    $unknown[$id] = true;
                }
            }
        }

        self::assertSame([], array_keys($unknown));
    }

    /**
     * Identifiers the examples publish that no published schema defines.
     *
     * `payment_tokenization` is this SDK's own invention: at `2026-08-25` tokenization is a
     * payment *handler* concern -- `handlers/tokenization/openapi.json` -- and not a shopping
     * capability, so no conformant peer negotiates it under this name. It is listed rather than
     * added to the enum, because putting it there would make an invented id look normative.
     *
     * `catalog.product` used to be here too, until this test found it: it was in the enum and
     * stamped into every product-detail response envelope. Both its schemas come from
     * `catalog_lookup.json`, so product detail is part of the lookup capability and the id was
     * never anything a peer could recognise.
     */
    private const NOT_IN_THE_SPEC = [
        'dev.ucp.shopping.payment_tokenization',
    ];

    #[Test]
    public function theEnumOnlyNamesIdentifiersThePinnedSchemasDefine(): void
    {
        $schemas = $this->projectRoot() . '/packages/core/resources/schema/pinned/'
            . UcpProtocolVersion::current()->value . '/schemas';
        $corpus = '';

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($schemas));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'json') {
                $corpus .= (string) file_get_contents($file->getPathname());
            }
        }

        $invented = [];
        foreach (UcpCapability::cases() as $case) {
            if (! str_contains($corpus, '"' . $case->value . '"')) {
                $invented[] = $case->value;
            }
        }

        self::assertSame([], $invented, 'The enum should not name capabilities the spec does not define.');
    }

    /**
     * @return list<string>
     */
    private function productionSources(): array
    {
        return $this->phpFilesIn(['packages/core/src', 'packages/symfony-bundle/src']);
    }

    /**
     * @return list<string>
     */
    private function exampleSources(): array
    {
        return $this->phpFilesIn(['examples/merchant-symfony-app/src', 'examples/bootstrap-symfony-app/src']);
    }

    /**
     * @param list<string> $directories
     *
     * @return list<string>
     */
    private function phpFilesIn(array $directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $root = $this->projectRoot() . '/' . $directory;
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Comments are not the contract. A version named in a docblock explaining why something
     * changed is documentation; one in an expression is a second source of truth.
     */
    private function stripComments(string $source): string
    {
        $kept = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    private function relative(string $path): string
    {
        return str_replace($this->projectRoot() . '/', '', $path);
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }
}
