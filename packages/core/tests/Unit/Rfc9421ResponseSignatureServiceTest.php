<?php

declare(strict_types=1);

namespace Ucp\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ucp\Sdk\Enum\SignatureAlgorithm;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Internal\Security\ContentDigestService;
use Ucp\Sdk\Internal\Security\DefaultSigningKeyManager;
use Ucp\Sdk\Internal\Security\EcdsaSignatureCodec;
use Ucp\Sdk\Internal\Security\Rfc9421ResponseSignatureService;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponse;
use Ucp\Sdk\Model\Security\ManagedSigningKey;

/**
 * Response signing, and specifically what the signature is bound to.
 *
 * The interesting property is not that a response can be signed -- it is that the signature
 * says which request it answers, so an intact one cannot be replayed against a different call.
 */
final class Rfc9421ResponseSignatureServiceTest extends TestCase
{
    #[Test]
    public function itCoversTheStatusAndBindsTheRequest(): void
    {
        $headers = $this->sign(
            new HttpResponse(200, [], '{"id":"chk_1"}'),
            new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions'),
        );

        self::assertStringContainsString('"@status"', $headers['Signature-Input']);
        self::assertStringContainsString('"@method";req', $headers['Signature-Input']);
        self::assertStringContainsString('"@target-uri";req', $headers['Signature-Input']);
        self::assertMatchesRegularExpression('/^sig=:[A-Za-z0-9+\/=]+:$/', $headers['Signature']);
    }

    #[Test]
    public function theSignatureChangesWhenOnlyTheRequestChanges(): void
    {
        // The whole point of the `;req` binding. Same bytes out, different question answered --
        // and without this the two would be interchangeable.
        $response = new HttpResponse(200, [], '{"id":"chk_1"}');
        $created = time();

        $toCheckout = $this->sign($response, new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions'), $created);
        $toCart = $this->sign($response, new HttpRequest('POST', 'https://merchant.example/ucp/v1/carts'), $created);

        self::assertNotSame($toCheckout['Signature'], $toCart['Signature']);
    }

    #[Test]
    public function theSignatureChangesWithTheStatus(): void
    {
        $request = new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions');
        $created = time();

        $created201 = $this->sign(new HttpResponse(201, [], '{"id":"chk_1"}'), $request, $created);
        $created200 = $this->sign(new HttpResponse(200, [], '{"id":"chk_1"}'), $request, $created);

        self::assertNotSame($created201['Signature'], $created200['Signature']);
    }

    #[Test]
    public function aBodyIsDescribedByAContentDigest(): void
    {
        $headers = $this->sign(
            new HttpResponse(200, [], '{"id":"chk_1"}'),
            new HttpRequest('GET', 'https://merchant.example/ucp/v1/checkout-sessions/chk_1'),
        );

        self::assertArrayHasKey('Content-Digest', $headers);
        self::assertStringContainsString('"content-digest"', $headers['Signature-Input']);
        self::assertSame(
            (new ContentDigestService())->create('{"id":"chk_1"}'),
            $headers['Content-Digest'],
        );
    }

    #[Test]
    public function aBodylessResponseNeitherCarriesNorCoversADigest(): void
    {
        // Content-Digest is representation metadata. A 204 describes no representation, so
        // there is nothing for a digest to be the digest of.
        $headers = $this->sign(
            new HttpResponse(204),
            new HttpRequest('POST', 'https://merchant.example/ucp/v1/checkout-sessions/chk_1/cancel'),
        );

        self::assertArrayNotHasKey('Content-Digest', $headers);
        self::assertStringNotContainsString('content-digest', $headers['Signature-Input']);
    }

    #[Test]
    public function theSignatureCarriesTheRegistryAlgorithmName(): void
    {
        $headers = $this->sign(
            new HttpResponse(200, [], '{}'),
            new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/ord_1'),
        );

        self::assertStringContainsString(
            'alg="' . SignatureAlgorithm::Es256->wireIdentifier() . '"',
            $headers['Signature-Input'],
        );
    }

    #[Test]
    public function theSignatureIsFixedWidthRatherThanDer(): void
    {
        $headers = $this->sign(
            new HttpResponse(200, [], '{}'),
            new HttpRequest('GET', 'https://merchant.example/ucp/v1/orders/ord_1'),
        );

        // substr rather than a capture group: an assertion does not narrow the match array for
        // static analysis, and the shape here is fixed enough that slicing says the same thing.
        $encoded = substr($headers['Signature'], strlen('sig=:'), -1);
        $raw = base64_decode($encoded, true);

        self::assertIsString($raw);
        self::assertSame(64, strlen($raw), 'P-256 is 32 bytes of r and 32 of s');
    }

    #[Test]
    public function aDerivedComponentWithNoResponseMeaningIsRefused(): void
    {
        // `@method` describes a request. Resolving it against the request anyway, without the
        // `;req` marker, would sign something other than what the label claims is covered.
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('not defined for responses');

        $this->sign(
            new HttpResponse(200, [], '{}'),
            new HttpRequest('GET', 'https://merchant.example/x'),
            components: ['@status', '@method'],
        );
    }

    #[Test]
    public function aSignedResponseFieldThatIsAbsentIsRefused(): void
    {
        // Signing a field the response does not carry would produce a signature nobody can
        // verify, and the failure would surface at the peer rather than here.
        $this->expectException(SignatureException::class);
        $this->expectExceptionMessage('does not carry the signed field');

        $this->sign(
            new HttpResponse(200, [], '{}'),
            new HttpRequest('GET', 'https://merchant.example/x'),
            components: ['@status', 'x-absent'],
        );
    }

    #[Test]
    public function aResponseFieldThatIsPresentIsCovered(): void
    {
        $headers = $this->sign(
            new HttpResponse(200, ['Idempotency-Replay' => '1'], '{}'),
            new HttpRequest('GET', 'https://merchant.example/x'),
            components: ['@status', 'idempotency-replay'],
        );

        self::assertStringContainsString('"idempotency-replay"', $headers['Signature-Input']);
    }

    private ?ManagedSigningKey $key = null;

    /**
     * One key for the whole case. Two signatures made with different keys differ whatever else
     * is true, so reusing one is what makes "the signature changed" mean the covered bytes
     * changed.
     */
    private function key(): ManagedSigningKey
    {
        return $this->key ??= (new DefaultSigningKeyManager())->generate('kid-1');
    }

    /**
     * @param list<string>|null $components
     *
     * @return array<string, string>
     */
    private function sign(
        HttpResponse $response,
        HttpRequest $request,
        ?int $created = null,
        ?array $components = null,
    ): array {
        $service = new Rfc9421ResponseSignatureService(
            new ContentDigestService(),
            signatureCodec: new EcdsaSignatureCodec(),
            signedComponents: $components ?? Rfc9421ResponseSignatureService::DEFAULT_SIGNED_COMPONENTS,
        );

        return $service->sign($response, $request, $this->key(), $created);
    }
}
