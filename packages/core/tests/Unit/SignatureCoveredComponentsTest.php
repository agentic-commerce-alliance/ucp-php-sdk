<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\Rfc9421RequestSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Security\ManagedSigningKey;

/**
 * Verification has to rebuild the signature base from what the peer said it covered.
 *
 * It used to parse the covered-component list and then throw it away, rebuilding the base from
 * a fixed three-component list of its own. Any peer covering a different set, or the same set in
 * a different order, was therefore verified against a base nobody had signed -- and failed, 100%
 * of the time, with "signature verification failed".
 *
 * These tests build the base by hand from the RFC's rules rather than by calling the code under
 * test, so they fail if this SDK's idea of the base format drifts. (RFC 9421's own Appendix B
 * vectors name algorithms by registry identifier, e.g. ecdsa-p256-sha256, which this SDK does
 * not accept yet; they belong with that change.)
 */
final class SignatureCoveredComponentsTest extends TestCase
{
    private const BODY = '{"line_items":[]}';

    #[Test]
    public function itVerifiesASignatureOverComponentsThisSdkDoesNotItselfSign(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-external');
        $created = time();
        $components = ['@method', '@authority', '@path', 'content-digest', 'content-type'];

        $request = $this->signedRequest($key, $components, $created);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [(new DefaultSigningKeyManager())->toPublicKey($key)]);

        self::assertTrue($result->verified, (string) $result->failureReason);
        self::assertSame('kid-external', $result->kid);
    }

    #[Test]
    public function itTreatsComponentOrderAsSignificant(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-order');
        $created = time();
        $signedOver = ['@method', '@authority', '@path', 'content-digest', 'content-type'];
        $claimed = ['@authority', '@method', '@path', 'content-digest', 'content-type'];

        // Signature made over one order, Signature-Input announcing another.
        $request = $this->signedRequest($key, $signedOver, $created, $claimed);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [(new DefaultSigningKeyManager())->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertSame('Request signature verification failed.', $result->failureReason);
    }

    /**
     * Unknown parameters must survive into the base byte for byte. Re-serialising from the
     * parameters this implementation happens to read would drop the rest -- and dropping any of
     * them changes the base, so a correctly signed request would stop verifying. `tag` is the
     * concrete case: Web Bot Auth signatures carry it today, before this SDK reads it.
     */
    #[Test]
    public function itKeepsSignatureInputParametersItDoesNotUnderstand(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-tag');
        $created = time();
        $components = ['@method', '@target-uri', 'content-digest'];
        $params = sprintf(
            '(%s);created=%d;expires=%d;keyid="%s";alg="%s";tag="web-bot-auth"',
            implode(' ', array_map(static fn (string $c): string => '"' . $c . '"', $components)),
            $created,
            $created + 120,
            $key->kid,
            $key->algorithm,
        );

        $request = $this->signedRequest($key, $components, $created, null, $params);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [(new DefaultSigningKeyManager())->toPublicKey($key)]);

        self::assertTrue($result->verified, (string) $result->failureReason);
    }

    #[Test]
    public function itRefusesAnUnsupportedDerivedComponentRatherThanOmittingIt(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-unsupported');
        $created = time();

        $request = $this->signedRequest($key, ['@method', 'content-digest', '@nonsense'], $created);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [(new DefaultSigningKeyManager())->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertSame('Unsupported signature component "@nonsense".', $result->failureReason);
    }

    #[Test]
    public function itRefusesAParameterisedComponent(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-parameterised');
        $created = time();

        $request = $this->signedRequest($key, ['@method', 'content-digest', '@query-param;name="q"'], $created);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [(new DefaultSigningKeyManager())->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertStringContainsString('carries parameters', (string) $result->failureReason);
    }

    #[Test]
    public function itRefusesACoveredHeaderTheRequestDoesNotCarry(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-missing-header');
        $created = time();

        $request = $this->signedRequest($key, ['@method', 'content-digest', 'x-absent'], $created);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [(new DefaultSigningKeyManager())->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertSame(
            'Signature covers header "x-absent", which the request does not carry.',
            $result->failureReason,
        );
    }

    #[Test]
    public function itRefusesAMalformedCoveredComponentList(): void
    {
        $key = (new DefaultSigningKeyManager())->generate('kid-malformed');
        $created = time();
        $digest = (new ContentDigestService())->create(self::BODY);

        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/carts', [
            'Content-Digest' => $digest,
            'Content-Type' => 'application/json',
            // A bare token where a quoted component belongs: consumed by nothing, and silently
            // ignoring it would mean treating an uncovered request as covered.
            'Signature-Input' => sprintf('sig=("@method" bogus);created=%d;expires=%d;keyid="%s";alg="%s"', $created, $created + 120, $key->kid, $key->algorithm),
            'Signature' => 'sig=:' . base64_encode('not-checked') . ':',
        ], [], self::BODY);

        $result = (new Rfc9421RequestSignatureService(new ContentDigestService()))
            ->verify($request, [(new DefaultSigningKeyManager())->toPublicKey($key)]);

        self::assertFalse($result->verified);
        self::assertSame('Signature-Input covered component list is malformed.', $result->failureReason);
    }

    /**
     * Builds the base by hand per RFC 9421 and signs it, independently of the service.
     *
     * @param list<string> $signedOver components the signature is actually computed over
     * @param list<string>|null $claimed components Signature-Input announces, when they differ
     */
    private function signedRequest(
        ManagedSigningKey $key,
        array $signedOver,
        int $created,
        ?array $claimed = null,
        ?string $paramsOverride = null,
    ): HttpRequest {
        $uri = 'https://merchant.example/ucp/v1/carts';
        $digest = (new ContentDigestService())->create(self::BODY);
        $values = [
            '@method' => 'POST',
            '@authority' => 'merchant.example',
            '@path' => '/ucp/v1/carts',
            '@target-uri' => $uri,
            'content-digest' => $digest,
            'content-type' => 'application/json',
        ];

        $announced = $claimed ?? $signedOver;
        $params = $paramsOverride ?? sprintf(
            '(%s);created=%d;expires=%d;keyid="%s";alg="%s"',
            implode(' ', array_map(static fn (string $c): string => '"' . $c . '"', $announced)),
            $created,
            $created + 120,
            $key->kid,
            $key->algorithm,
        );

        $lines = [];
        foreach ($signedOver as $component) {
            // Components with no defined value here are ones the service must reject before it
            // ever reaches openssl_verify, so the byte used is irrelevant.
            $lines[] = sprintf('"%s": %s', $component, $values[$component] ?? 'unresolvable');
        }

        $lines[] = sprintf('"@signature-params": %s', $params);
        $base = implode("\n", $lines);

        $signature = '';
        self::assertTrue(openssl_sign($base, $signature, $key->privateKeyPem, OPENSSL_ALGO_SHA256));

        return new HttpRequest('POST', $uri, [
            'Content-Digest' => $digest,
            'Content-Type' => 'application/json',
            'Signature-Input' => 'sig=' . $params,
            'Signature' => 'sig=:' . base64_encode($signature) . ':',
        ], [], self::BODY);
    }
}
