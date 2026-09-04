<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Symfony\Internal\OriginMatcher;

/**
 * Pins what this comparator refuses, which is the half that matters.
 *
 * It decides whether a browser origin may talk to a merchant, and the input is a request
 * header. Its happy path is reached constantly through the embedded transport; the
 * rejections were reached by nothing, and a comparator that is permissive about malformed
 * input is a comparator that can be talked past.
 */
final class OriginMatcherTest extends TestCase
{
    /**
     * An `Origin` header is a scheme, a host and optionally a port -- nothing else. A
     * value carrying a path, a query, a fragment or credentials is not an origin, and
     * normalising it by discarding the extra parts would make
     * `https://agent.example@attacker.example` compare equal to `https://agent.example`.
     * So these are refused rather than trimmed.
     */
    #[Test]
    #[DataProvider('nonOrigins')]
    public function aValueThatIsNotAnOriginIsRefused(string $candidate, string $why): void
    {
        self::assertNull(OriginMatcher::normalizeOrigin($candidate), $why);
        self::assertFalse(
            OriginMatcher::allows($candidate, [$candidate], 'https://merchant.example'),
            'A value that cannot be normalised must not match even when it is listed verbatim: ' . $why,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function nonOrigins(): iterable
    {
        yield 'path' => ['https://agent.example/callback', 'a path is not part of an origin'];
        yield 'query' => ['https://agent.example?a=b', 'a query is not part of an origin'];
        yield 'fragment' => ['https://agent.example#frag', 'a fragment is not part of an origin'];
        yield 'credentials' => ['https://user@agent.example', 'userinfo would let a host be spoofed by prefix'];
        yield 'unparseable' => ['http:///', 'parse_url rejects this outright'];
        yield 'no scheme' => ['agent.example', 'a bare host does not say http or https'];
        yield 'wrong scheme' => ['ftp://agent.example', 'only http and https are origins a browser sends'];
        yield 'scheme only' => ['https://', 'there is no host to compare'];
        yield 'empty' => ['', 'nothing to compare'];
    }

    /**
     * The base URI goes through a different normaliser, which deliberately tolerates the
     * path an ordinary configured URL carries -- `https://merchant.example/shop` is a
     * reasonable base URI and its origin is still `https://merchant.example`. Unparseable
     * input has to fall through to null there too, or a broken configuration would
     * contribute a garbage entry to the allow-list.
     */
    #[Test]
    public function aBaseUriIsReducedToItsOriginAndRefusedWhenUnparseable(): void
    {
        self::assertSame('https://merchant.example', OriginMatcher::normalizeUriOrigin('https://merchant.example/shop?x=1'));
        self::assertNull(OriginMatcher::normalizeUriOrigin('http:///'));
        self::assertNull(OriginMatcher::normalizeUriOrigin('not a uri at all'));
    }

    /**
     * The merchant's own origin is always allowed without being listed, because the
     * embedded pages are served from it and a same-origin frame is not a cross-origin
     * decision.
     */
    #[Test]
    public function theMerchantsOwnOriginIsAllowedWithoutBeingListed(): void
    {
        self::assertTrue(OriginMatcher::allows('https://merchant.example', [], 'https://merchant.example/shop'));
        self::assertFalse(OriginMatcher::allows('https://other.example', [], 'https://merchant.example/shop'));
    }

    /**
     * Default ports are dropped and non-default ones kept, so `https://agent.example:443`
     * and `https://agent.example` are the same origin while `:8443` is not. A browser
     * omits the default port, so treating them as different would refuse a correctly
     * configured allow-list entry.
     */
    #[Test]
    public function aDefaultPortIsEquivalentToNoPortAndANonDefaultOneIsNot(): void
    {
        self::assertTrue(OriginMatcher::allows('https://agent.example', ['https://agent.example:443'], 'https://merchant.example'));
        self::assertTrue(OriginMatcher::allows('http://agent.example:80', ['http://agent.example'], 'https://merchant.example'));
        self::assertFalse(OriginMatcher::allows('https://agent.example:8443', ['https://agent.example'], 'https://merchant.example'));
    }

    #[Test]
    public function comparisonIsCaseInsensitiveForTheSchemeAndHostOnly(): void
    {
        self::assertTrue(OriginMatcher::allows('HTTPS://Agent.Example', ['https://agent.example'], 'https://merchant.example'));
    }

    /**
     * An unusable entry in the allow-list is skipped rather than aborting the comparison,
     * so one typo does not switch off every other configured origin.
     */
    #[Test]
    public function anUnusableAllowListEntryDoesNotDisableTheUsableOnes(): void
    {
        self::assertTrue(OriginMatcher::allows(
            'https://agent.example',
            ['not-an-origin', 'https://agent.example'],
            'https://merchant.example',
        ));
    }
}
