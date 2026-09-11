<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Internal\OriginMatcher;

/**
 * Covers this facade at the boundary the embedded transport actually calls.
 *
 * The rules themselves are pinned exhaustively by AgentDomainAllowListTest in
 * `packages/core`, where they now live -- the platform-profile gate reads the same
 * configured list and cannot depend on this bundle. Restating all of them here would give
 * the repository two copies of one specification, which is how `allowed_agent_domains`
 * came to have two readings in the first place.
 *
 * What is worth asserting here is that the forwarding is right: three positional
 * arguments of which two are strings that look alike, so swapping the origin and the base
 * URI would type-check and quietly compare the wrong pair. The expectations are written
 * out rather than compared against the delegate, because a test that asks the delegate
 * what to expect cannot describe a wrong answer.
 */
final class OriginMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: list<string>, 2: bool}>
     */
    public static function cases(): iterable
    {
        yield 'bare domain' => ['https://agent.example', ['agent.example'], true];
        yield 'subdomain of a bare domain' => ['https://sub.agent.example', ['agent.example'], true];
        yield 'full origin' => ['https://agent.example', ['https://agent.example'], true];
        yield 'the merchant itself, unlisted' => ['https://merchant.example', [], true];

        yield 'subdomain of a full origin' => ['https://sub.agent.example', ['https://agent.example'], false];
        yield 'plaintext against a bare domain' => ['http://agent.example', ['agent.example'], false];
        yield 'non-default port against a bare domain' => ['https://agent.example:8443', ['agent.example'], false];
        yield 'unlisted origin' => ['https://attacker.example', ['agent.example'], false];
        yield 'origin carrying a path' => ['https://agent.example/callback', ['agent.example'], false];
        yield 'empty allow-list' => ['https://agent.example', [], false];
    }

    /**
     * @param list<string> $entries
     */
    #[Test]
    #[DataProvider('cases')]
    public function itAppliesTheAllowListToTheOriginAndNotToTheBaseUri(string $origin, array $entries, bool $allowed): void
    {
        self::assertSame($allowed, OriginMatcher::allows($origin, $entries, 'https://merchant.example'));
    }

    #[Test]
    public function itForwardsBothNormalisers(): void
    {
        self::assertSame('https://merchant.example', OriginMatcher::normalizeOrigin('https://merchant.example'));
        self::assertNull(OriginMatcher::normalizeOrigin('https://merchant.example/shop'), 'An Origin header carries no path.');

        self::assertSame('https://merchant.example', OriginMatcher::normalizeUriOrigin('https://merchant.example/shop?x=1'), 'A base URI legitimately does.');
        self::assertNull(OriginMatcher::normalizeUriOrigin('not a uri at all'));
    }
}
