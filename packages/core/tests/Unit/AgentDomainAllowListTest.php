<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\AgentDomainAllowList;

/**
 * Pins that `allowed_agent_domains` has one meaning.
 *
 * It used to have two. The platform-profile gate matched entries as bare domains with
 * subdomain matching, and the embedded transport's CORS check normalised them as full
 * origins and dropped anything without a scheme. The two formats were mutually exclusive:
 * `agent.example` satisfied the first and produced an empty allow-list for the second,
 * while `https://agent.example` did the reverse. Both gates fail closed, so whichever a
 * merchant wrote, one feature silently refused everything.
 *
 * The first test here is the one that would have caught it, and it is written as a
 * comparison between the two consumers rather than as an assertion about either -- the
 * defect was never visible from inside one of them.
 */
final class AgentDomainAllowListTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function entryForms(): iterable
    {
        yield 'bare domain' => ['agent.example'];
        yield 'full origin' => ['https://agent.example'];
    }

    /**
     * Both consumers have to accept the same entry. Neither form may be the one that
     * works for the profile gate and breaks framing, or the other way round.
     */
    #[Test]
    #[DataProvider('entryForms')]
    public function bothGatesAcceptTheSameEntry(string $entry): void
    {
        self::assertTrue(
            AgentDomainAllowList::matchesHost('agent.example', [$entry]),
            'The platform-profile gate must accept ' . $entry,
        );
        self::assertTrue(
            AgentDomainAllowList::allowsOrigin('https://agent.example', [$entry], 'https://merchant.example'),
            'The embedded CORS check must accept ' . $entry,
        );
    }

    /**
     * Subdomain matching is what makes the setting a domain list rather than a host list:
     * an agent operator publishes its profile at one name and frames from another.
     */
    #[Test]
    public function aDomainCoversItsSubdomains(): void
    {
        self::assertTrue(AgentDomainAllowList::matchesHost('profiles.agent.example', ['agent.example']));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('https://checkout.agent.example', ['agent.example'], 'https://merchant.example'));
    }

    /**
     * A suffix match must be on a label boundary. Without the dot, `agent.example` would
     * also admit `evil-agent.example`, which is a different registration entirely.
     */
    #[Test]
    public function aDomainDoesNotCoverAHostThatMerelyEndsWithItsText(): void
    {
        self::assertFalse(AgentDomainAllowList::matchesHost('evilagent.example', ['agent.example']));
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://evil-agent.example', ['agent.example'], 'https://merchant.example'));
    }

    /**
     * A bare domain says nothing about the scheme, and the safe reading of silence is
     * https. Admitting plaintext framing because an entry omitted a scheme would be a
     * downgrade the operator never asked for.
     */
    #[Test]
    public function aBareDomainDoesNotAdmitPlaintextFraming(): void
    {
        self::assertFalse(AgentDomainAllowList::allowsOrigin('http://agent.example', ['agent.example'], 'https://merchant.example'));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('https://agent.example', ['agent.example'], 'https://merchant.example'));
    }

    /**
     * Loopback is the exception, and not a new one -- the profile-fetching development
     * mode already carves out exactly these hosts. Without it, no one could develop
     * against the embedded transport locally.
     */
    #[Test]
    public function loopbackMayBeFramedOverHttp(): void
    {
        foreach (['localhost', '127.0.0.1'] as $host) {
            self::assertTrue(
                AgentDomainAllowList::allowsOrigin('http://' . $host, [$host], 'https://merchant.example'),
                $host . ' must be usable over http for local development',
            );
        }
    }

    /**
     * An entry written as a full origin keeps its scheme and port as a narrowing. Reducing
     * it to a bare domain would silently widen an allow-list somebody deliberately pinned.
     */
    #[Test]
    public function afullOriginEntryPinsTheSchemeAndPortItNames(): void
    {
        self::assertTrue(AgentDomainAllowList::allowsOrigin('https://agent.example:8443', ['https://agent.example:8443'], 'https://merchant.example'));
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://agent.example', ['https://agent.example:8443'], 'https://merchant.example'));
        self::assertFalse(AgentDomainAllowList::allowsOrigin('http://agent.example', ['https://agent.example'], 'https://merchant.example'));
    }

    /**
     * A bare domain does not cover a non-default port. A port is part of an origin, so
     * two ports are two security principals -- on a developer's machine `localhost:8081`
     * is very likely somebody else's application, and the merchant example configures a
     * bare `localhost`. An agent on another port is written out as a full origin.
     */
    #[Test]
    public function aBareDomainDoesNotCoverANonDefaultPort(): void
    {
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://localhost:8081', ['localhost'], 'http://localhost'));
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://agent.example:8443', ['agent.example'], 'https://merchant.example'));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('https://agent.example:8443', ['https://agent.example:8443'], 'https://merchant.example'));
    }

    /**
     * An entry written as an origin is compared as one, without subdomain matching.
     * Spelling out a scheme is a request to be specific, so widening it would loosen an
     * allow-list somebody deliberately pinned. A bare domain is where subdomains come in.
     */
    #[Test]
    public function anOriginEntryDoesNotCoverSubdomainsButADomainEntryDoes(): void
    {
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://sub.agent.example', ['https://agent.example'], 'https://merchant.example'));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('https://sub.agent.example', ['agent.example'], 'https://merchant.example'));
    }

    /**
     * A browser omits the default port, so an entry written with it must still match the
     * origin that actually arrives.
     */
    #[Test]
    public function aDefaultPortIsEquivalentToNoPort(): void
    {
        self::assertTrue(AgentDomainAllowList::allowsOrigin('https://agent.example', ['https://agent.example:443'], 'https://merchant.example'));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('http://localhost:80', ['http://localhost'], 'https://merchant.example'));
    }

    #[Test]
    public function theMerchantsOwnOriginIsAllowedWithoutBeingListed(): void
    {
        self::assertTrue(AgentDomainAllowList::allowsOrigin('https://merchant.example', [], 'https://merchant.example/shop'));
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://other.example', [], 'https://merchant.example/shop'));
    }

    /**
     * An `Origin` is a scheme, a host and optionally a port -- nothing else. These are
     * refused rather than trimmed: reducing `https://agent.example@attacker.example` to a
     * host would make it compare equal to `https://agent.example`.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function nonOrigins(): iterable
    {
        yield 'path' => ['https://agent.example/callback', 'a path is not part of an origin'];
        yield 'query' => ['https://agent.example?a=b', 'a query is not part of an origin'];
        yield 'fragment' => ['https://agent.example#frag', 'a fragment is not part of an origin'];
        yield 'credentials' => ['https://user@agent.example', 'userinfo would let a host be spoofed by prefix'];
        yield 'unparseable' => ['http:///', 'parse_url rejects this outright'];
        yield 'wrong scheme' => ['ftp://agent.example', 'only http and https are origins a browser sends'];
        yield 'scheme only' => ['https://', 'there is no host to compare'];
        yield 'empty' => ['', 'nothing to compare'];
    }

    #[Test]
    #[DataProvider('nonOrigins')]
    public function aValueThatIsNotAnOriginIsRefused(string $candidate, string $why): void
    {
        self::assertNull(AgentDomainAllowList::canonicalOrigin($candidate), $why);
        self::assertFalse(
            AgentDomainAllowList::allowsOrigin($candidate, ['agent.example'], 'https://merchant.example'),
            $why,
        );
    }

    /**
     * A bare host is not an `Origin` header value -- a browser always sends a scheme --
     * but it is a legitimate allow-list entry, which is the whole point of the fix.
     */
    #[Test]
    public function aBareHostIsAValidEntryButNotAValidOrigin(): void
    {
        self::assertNull(AgentDomainAllowList::canonicalOrigin('agent.example'));
        self::assertTrue(AgentDomainAllowList::isUsableEntry('agent.example'));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function entries(): iterable
    {
        yield 'domain' => ['agent.example', true];
        yield 'subdomain' => ['profiles.agent.example', true];
        yield 'single label' => ['localhost', true];
        yield 'ipv4 literal' => ['127.0.0.1', true];
        yield 'ipv6 literal' => ['::1', true];
        yield 'bracketed ipv6' => ['[::1]', true];
        yield 'https origin' => ['https://agent.example', true];
        yield 'origin with port' => ['https://agent.example:8443', true];
        yield 'mixed case' => ['HTTPS://Agent.Example', true];
        yield 'empty' => ['', false];
        yield 'whitespace' => ['   ', false];
        yield 'domain with path' => ['agent.example/callback', false];
        yield 'domain with port' => ['agent.example:8443', false];
        yield 'domain with userinfo' => ['user@agent.example', false];
        yield 'wildcard' => ['*.agent.example', false];
        yield 'bare star' => ['*', false];
        yield 'wrong scheme' => ['ftp://agent.example', false];
        yield 'origin with path' => ['https://agent.example/callback', false];
        yield 'leading dot' => ['.agent.example', false];
        yield 'trailing dot' => ['agent.example.', false];
        yield 'leading hyphen' => ['-agent.example', false];
    }

    /**
     * The container refuses an unusable entry at build time, so this is the list of what
     * counts. A wildcard is refused deliberately: subdomain matching is already implied
     * by a bare domain, so `*.agent.example` would either be redundant or read as
     * something this does not implement.
     */
    #[Test]
    #[DataProvider('entries')]
    public function itReportsWhichEntriesItCanActOn(string $entry, bool $usable): void
    {
        self::assertSame($usable, AgentDomainAllowList::isUsableEntry($entry));
    }

    /**
     * One typo must not switch off every other configured domain. It is refused at
     * container build now, but a runtime configuration resolver can produce this list
     * dynamically and does not go through that check.
     */
    #[Test]
    public function anUnusableEntryDoesNotDisableTheUsableOnes(): void
    {
        self::assertTrue(AgentDomainAllowList::allowsOrigin(
            'https://agent.example',
            ['*.nonsense', 'agent.example'],
            'https://merchant.example',
        ));
        self::assertTrue(AgentDomainAllowList::matchesHost('agent.example', ['*.nonsense', 'agent.example']));
    }

    /**
     * The loopback set is configured as addresses, not names -- `localhost`, `127.0.0.1`
     * and `::1` are one host, and which spelling appears depends on how the app was
     * started rather than on who it should trust. Rejecting the address forms left the
     * merchant example unable to compile its own container.
     */
    #[Test]
    public function anAddressLiteralIsAUsableEntryInEverySpelling(): void
    {
        $loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];

        foreach ($loopback as $entry) {
            self::assertTrue(AgentDomainAllowList::isUsableEntry($entry), $entry . ' must be usable');
        }

        self::assertTrue(AgentDomainAllowList::matchesHost('127.0.0.1', $loopback));
        self::assertTrue(AgentDomainAllowList::matchesHost('::1', $loopback));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('http://127.0.0.1', $loopback, 'http://localhost'));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('http://[::1]', $loopback, 'http://localhost'));
    }

    /**
     * An address has no subdomains, and suffix-matching one would be actively wrong: with
     * `str_ends_with($host, '.' . '127.0.0.1')` gone, the guard is what stops a name like
     * `evil.127.0.0.1` -- registrable in some resolvers -- from matching.
     */
    #[Test]
    public function anAddressEntryMatchesExactlyAndNotAsASuffix(): void
    {
        self::assertFalse(AgentDomainAllowList::matchesHost('evil.127.0.0.1', ['127.0.0.1']));
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://evil.127.0.0.1', ['127.0.0.1'], 'https://merchant.example'));
        self::assertTrue(AgentDomainAllowList::matchesHost('127.0.0.1', ['127.0.0.1']));
    }

    /**
     * A bracketed and a bare IPv6 entry mean the same host, and a browser sends the
     * bracketed form -- so the two spellings have to be interchangeable in both positions.
     */
    #[Test]
    public function bracketedAndBareIpv6AreTheSameHost(): void
    {
        self::assertTrue(AgentDomainAllowList::allowsOrigin('http://[::1]', ['::1'], 'https://merchant.example'));
        self::assertTrue(AgentDomainAllowList::allowsOrigin('http://[::1]', ['[::1]'], 'https://merchant.example'));
        self::assertSame('http://[::1]', AgentDomainAllowList::canonicalOrigin('http://[::1]'));
        self::assertSame('http://[::1]:8081', AgentDomainAllowList::canonicalOrigin('http://[::1]:8081'));
    }

    #[Test]
    public function comparisonIsCaseInsensitive(): void
    {
        self::assertTrue(AgentDomainAllowList::allowsOrigin('HTTPS://Agent.Example', ['agent.example'], 'https://merchant.example'));
        self::assertTrue(AgentDomainAllowList::matchesHost('Agent.Example', ['AGENT.EXAMPLE']));
    }

    /**
     * An empty allow-list means "nothing beyond the merchant itself", not "everything".
     * The profile gate skips the check entirely when the list is empty -- that decision
     * lives in its caller -- so what this must report is no match.
     */
    #[Test]
    public function anEmptyAllowListMatchesNothing(): void
    {
        self::assertFalse(AgentDomainAllowList::matchesHost('agent.example', []));
        self::assertFalse(AgentDomainAllowList::allowsOrigin('https://agent.example', [], 'https://merchant.example'));
    }

    #[Test]
    public function aBaseUriIsReducedToItsOrigin(): void
    {
        self::assertSame('https://merchant.example', AgentDomainAllowList::originOfUri('https://merchant.example/shop?x=1'));
        self::assertSame('https://merchant.example:8443', AgentDomainAllowList::originOfUri('https://merchant.example:8443/shop'));
        self::assertNull(AgentDomainAllowList::originOfUri('not a uri at all'));
    }
}
