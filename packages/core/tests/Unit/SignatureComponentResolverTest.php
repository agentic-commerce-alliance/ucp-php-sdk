<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\SignatureComponentResolver;
use Ucp\Sdk\Model\Http\HttpRequest;

/**
 * Every component the resolver claims to support, and every normalisation it applies.
 *
 * These matter byte for byte: the value goes into the signature base, so a peer that normalises
 * a component differently than we do produces a different base and its signature simply does
 * not verify. There is no partial credit and no diagnostic -- the failure is indistinguishable
 * from a forged signature.
 */
final class SignatureComponentResolverTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('components')]
    public function itResolvesComponentsToTheirSignatureBaseValue(
        string $method,
        string $uri,
        array $headers,
        string $component,
        string $expected,
    ): void {
        $resolved = (new SignatureComponentResolver())
            ->resolve(new HttpRequest($method, $uri, $headers), $component, $headers);

        self::assertSame($expected, $resolved);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string, string}>
     */
    public static function components(): iterable
    {
        $uri = 'https://merchant.example/ucp/v1/carts?a=1&b=2';

        yield '@method is uppercased' => ['post', $uri, [], '@method', 'POST'];
        yield '@method already uppercase' => ['GET', $uri, [], '@method', 'GET'];
        yield '@target-uri is verbatim' => ['GET', $uri, [], '@target-uri', $uri];
        yield '@path' => ['GET', $uri, [], '@path', '/ucp/v1/carts'];
        yield '@path defaults to root' => ['GET', 'https://merchant.example', [], '@path', '/'];
        yield '@query keeps the raw string' => ['GET', $uri, [], '@query', '?a=1&b=2'];
        // RFC 9421 section 2.2.7: an absent query is the leading '?' alone.
        yield '@query is bare ? when absent' => ['GET', 'https://merchant.example/x', [], '@query', '?'];
        yield '@request-target includes the query' => ['GET', $uri, [], '@request-target', '/ucp/v1/carts?a=1&b=2'];
        yield '@request-target omits an absent query' => ['GET', 'https://merchant.example/x', [], '@request-target', '/x'];
        yield '@scheme is lowercased' => ['GET', 'HTTPS://merchant.example/x', [], '@scheme', 'https'];

        // @authority: host lowercased, default port for the scheme omitted, any other port kept.
        yield '@authority plain' => ['GET', $uri, [], '@authority', 'merchant.example'];
        yield '@authority lowercases the host' => ['GET', 'https://Merchant.EXAMPLE/x', [], '@authority', 'merchant.example'];
        yield '@authority omits the default https port' => ['GET', 'https://merchant.example:443/x', [], '@authority', 'merchant.example'];
        yield '@authority omits the default http port' => ['GET', 'http://merchant.example:80/x', [], '@authority', 'merchant.example'];
        yield '@authority keeps a non-default https port' => ['GET', 'https://merchant.example:8443/x', [], '@authority', 'merchant.example:8443'];
        yield '@authority keeps a non-default http port' => ['GET', 'http://merchant.example:8080/x', [], '@authority', 'merchant.example:8080'];
        yield '@authority keeps 443 on a non-https scheme' => ['GET', 'http://merchant.example:443/x', [], '@authority', 'merchant.example:443'];
        yield '@authority keeps 80 on a non-http scheme' => ['GET', 'https://merchant.example:80/x', [], '@authority', 'merchant.example:80'];

        // RFC 9421 section 2.1: field values have surrounding whitespace stripped.
        yield 'header field' => ['GET', $uri, ['content-type' => 'application/json'], 'content-type', 'application/json'];
        yield 'header field is trimmed' => ['GET', $uri, ['content-type' => "  application/json \t"], 'content-type', 'application/json'];
        yield 'header field may be empty' => ['GET', $uri, ['x-empty' => ''], 'x-empty', ''];
    }

    #[Test]
    public function itRefusesAnEmptyComponentName(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signature-Input covers a component with an empty name.');

        $this->resolve('');
    }

    #[Test]
    public function itRefusesAnUnknownDerivedComponent(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Unsupported signature component "@status".');

        // @status is real, but response-only -- so it is not resolvable against a request, and
        // guessing a value would verify a signature over something the peer did not sign.
        $this->resolve('@status');
    }

    #[Test]
    public function itRefusesAParameterisedComponent(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('carries parameters');

        $this->resolve('@query-param;name="a"');
    }

    #[Test]
    public function itRefusesAHeaderTheRequestDoesNotCarry(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signature covers header "x-missing", which the request does not carry.');

        $this->resolve('x-missing');
    }

    #[Test]
    public function itRefusesAnAuthorityItCannotDerive(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signature covers "@authority", but the request URI "/relative/path" has no such part.');

        $this->resolve('@authority', '/relative/path');
    }

    #[Test]
    public function itRefusesASchemeItCannotDerive(): void
    {
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('Signature covers "@scheme", but the request URI "/relative/path" has no such part.');

        $this->resolve('@scheme', '/relative/path');
    }

    private function resolve(string $component, string $uri = 'https://merchant.example/x'): string
    {
        return (new SignatureComponentResolver())->resolve(new HttpRequest('GET', $uri), $component, []);
    }
}
