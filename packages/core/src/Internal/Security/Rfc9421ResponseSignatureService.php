<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Enum\SignatureAlgorithm;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Http\HttpResponse;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Service\ResponseSignatureServiceInterface;

/**
 * Signs REST responses per RFC 9421, bound to the request that produced them.
 *
 * @internal
 */
final class Rfc9421ResponseSignatureService implements ResponseSignatureServiceInterface
{
    private const LABEL = 'sig';
    private const CONTENT_DIGEST = 'content-digest';

    /**
     * `@status` says what this response is; the two `;req` components say what it answers.
     *
     * Without the request binding a signature is transferable: an intact, correctly signed
     * response to one request verifies just as well against another. The business would be
     * saying "this is a real answer of mine" without saying what it answers, which is most of
     * what a caller wanted to know.
     */
    public const DEFAULT_SIGNED_COMPONENTS = ['@status', '"@method";req', '"@target-uri";req'];

    /**
     * @param list<string> $signedComponents
     */
    public function __construct(
        private readonly ContentDigestService $contentDigestService,
        private readonly SignatureComponentResolver $componentResolver = new SignatureComponentResolver(),
        private readonly EcdsaSignatureCodec $signatureCodec = new EcdsaSignatureCodec(),
        private readonly int $maxLifetimeSeconds = 300,
        private readonly array $signedComponents = self::DEFAULT_SIGNED_COMPONENTS,
    ) {
    }

    public function sign(
        HttpResponse $response,
        HttpRequest $request,
        ManagedSigningKey $key,
        ?int $created = null,
        ?int $expires = null,
    ): array {
        $created ??= time();
        $expires ??= $created + $this->maxLifetimeSeconds;
        $algorithm = SignatureAlgorithm::fromIdentifier($key->algorithm);

        // Representation metadata, exactly as on requests: a body-less response describes no
        // representation, so it neither carries nor covers a digest.
        $hasBody = $response->body !== '';
        $digest = $hasBody ? $this->contentDigestService->create($response->body) : null;

        $components = $this->signedComponents;
        if ($hasBody) {
            $components = [...$components, self::CONTENT_DIGEST];
        }

        $signatureParams = sprintf(
            '(%s);created=%d;expires=%d;keyid="%s";alg="%s"',
            implode(' ', array_map(
                static fn (string $component): string => str_contains($component, ';')
                    ? $component
                    : '"' . $component . '"',
                $components,
            )),
            $created,
            $expires,
            $key->kid,
            $algorithm->wireIdentifier(),
        );

        $headers = [];
        foreach ($response->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        if ($digest !== null) {
            $headers[self::CONTENT_DIGEST] = $digest;
        }

        $base = $this->signatureBase($response, $request, $components, $signatureParams, $headers);

        $signature = '';
        if (! openssl_sign($base, $signature, $key->privateKeyPem, $this->opensslAlgorithm($algorithm))) {
            throw new SignatureException('Unable to sign response.');
        }

        $signed = [
            'Signature-Input' => self::LABEL . '=' . $signatureParams,
            'Signature' => self::LABEL . '=:' . base64_encode(
                $this->signatureCodec->derToRaw($signature, $algorithm->coordinateBytes()),
            ) . ':',
        ];

        return $digest === null ? $signed : ['Content-Digest' => $digest, ...$signed];
    }

    /**
     * @param list<string> $components
     * @param array<string, string> $headers
     */
    private function signatureBase(
        HttpResponse $response,
        HttpRequest $request,
        array $components,
        string $signatureParams,
        array $headers,
    ): string {
        $lines = [];

        foreach ($components as $component) {
            $lines[] = sprintf(
                '%s: %s',
                str_contains($component, ';') ? $component : '"' . $component . '"',
                $this->resolve($response, $request, $component, $headers),
            );
        }

        $lines[] = sprintf('"@signature-params": %s', $signatureParams);

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $headers
     */
    private function resolve(HttpResponse $response, HttpRequest $request, string $component, array $headers): string
    {
        // `;req` is the one parameter RFC 9421 defines for response signatures, and it means
        // "resolve this against the request instead". Everything else parameterised is still
        // refused by the request resolver, which is where that strictness belongs.
        if (str_ends_with($component, ';req')) {
            $name = trim(substr($component, 0, -4), '"');

            return $this->componentResolver->resolve($request, $name, $this->requestHeaders($request));
        }

        if ($component === '@status') {
            return (string) $response->statusCode;
        }

        if (str_starts_with($component, '@')) {
            throw new SignatureException(sprintf(
                'Derived component "%s" is not defined for responses; bind it to the request with ";req".',
                $component,
            ));
        }

        $value = $headers[strtolower($component)] ?? null;
        if ($value === null) {
            throw new SignatureException(sprintf('Response does not carry the signed field "%s".', $component));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(HttpRequest $request): array
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    private function opensslAlgorithm(SignatureAlgorithm $algorithm): int
    {
        return match ($algorithm) {
            SignatureAlgorithm::Es256 => OPENSSL_ALGO_SHA256,
            SignatureAlgorithm::Es384 => OPENSSL_ALGO_SHA384,
        };
    }
}
