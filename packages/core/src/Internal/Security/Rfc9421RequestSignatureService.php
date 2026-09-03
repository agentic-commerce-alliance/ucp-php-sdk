<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Model\Security\SignatureVerificationResult;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;
use Ucp\Sdk\Service\SignatureReplayGuardInterface;

/** @internal */
final class Rfc9421RequestSignatureService implements RequestSignatureServiceInterface
{
    /** @var list<string> */
    private const SUPPORTED_ALGORITHMS = ['ES256', 'ES384'];
    private const DEFAULT_SIGNATURE_LABEL = 'sig';

    /** @var list<string> */
    private const SIGNED_COMPONENTS = ['@method', '@target-uri', 'content-digest'];

    private readonly SignatureComponentResolver $componentResolver;

    public function __construct(
        private readonly ContentDigestService $contentDigestService,
        private readonly ?SignatureReplayGuardInterface $replayGuard = null,
        private readonly int $maxLifetimeSeconds = 300,
        ?SignatureComponentResolver $componentResolver = null,
    ) {
        $this->componentResolver = $componentResolver ?? new SignatureComponentResolver();
    }

    public function sign(HttpRequest $request, ManagedSigningKey $key, ?int $created = null, ?int $expires = null): array
    {
        $created ??= time();
        $expires ??= $created + $this->maxLifetimeSeconds;
        $this->assertSupportedAlgorithm($key->algorithm);
        $digest = $this->contentDigestService->create($request->body);
        $signatureParams = sprintf(
            '(%s);created=%d;expires=%d;keyid="%s";alg="%s"',
            implode(' ', array_map(static fn (string $component): string => '"' . $component . '"', self::SIGNED_COMPONENTS)),
            $created,
            $expires,
            $key->kid,
            $key->algorithm,
        );
        $signatureInput = self::DEFAULT_SIGNATURE_LABEL . '=' . $signatureParams;

        // The digest is not on the request yet, so overlay the one about to be sent.
        $headers = $this->normalizeHeaders($request->headers);
        $headers['content-digest'] = $digest;
        $base = $this->signatureBase($request, self::SIGNED_COMPONENTS, $signatureParams, $headers);
        $signature = '';
        $opensslAlgorithm = $this->opensslAlgorithm($key->algorithm);

        if (!openssl_sign($base, $signature, $key->privateKeyPem, $opensslAlgorithm)) {
            throw new SignatureException('Unable to sign request.');
        }

        return [
            'Content-Digest' => $digest,
            'Signature-Input' => $signatureInput,
            'Signature' => self::DEFAULT_SIGNATURE_LABEL . '=:' . base64_encode($signature) . ':',
        ];
    }

    public function verify(HttpRequest $request, array $keys): SignatureVerificationResult
    {
        $headers = $this->normalizeHeaders($request->headers);
        $signatureInput = $headers['signature-input'] ?? null;
        $signatureHeader = $headers['signature'] ?? null;
        $digest = $headers['content-digest'] ?? null;
        $contentDigestVerified = false;

        if ($signatureInput === null || $signatureHeader === null) {
            return new SignatureVerificationResult(false, failureReason: 'Missing signature headers.');
        }

        try {
            [$parts, $components, $signatureParams] = $this->parseSignatureInput($signatureInput);
            $label = $parts['@label'] ?? self::DEFAULT_SIGNATURE_LABEL;
            $kid = $parts['keyid'] ?? null;
            $requestedAlgorithm = $parts['alg'] ?? null;
            $created = isset($parts['created']) ? (int) $parts['created'] : null;
            $expires = isset($parts['expires']) ? (int) $parts['expires'] : null;

            if ($kid === null || $created === null || $expires === null) {
                throw new SignatureException('Signature-Input is missing required parameters.');
            }

            if ($requestedAlgorithm !== null) {
                $this->assertSupportedAlgorithm($requestedAlgorithm);
            }

            if ($created > time() + 60) {
                throw new SignatureException('Signature created timestamp is too far in the future.');
            }

            if ($expires < time() - 60) {
                throw new SignatureException('Signature has expired.');
            }

            if (($expires - $created) > $this->maxLifetimeSeconds) {
                throw new SignatureException('Signature lifetime exceeds the allowed window.');
            }

            $this->contentDigestService->verify($request->body, $digest);
            $contentDigestVerified = true;
            $signature = $this->extractSignatureValue($signatureHeader, $label);
            $key = $this->resolveKey($keys, $kid);
            $this->assertSupportedAlgorithm($key->algorithm);
            if ($requestedAlgorithm !== null && $requestedAlgorithm !== $key->algorithm) {
                throw new SignatureException('Signature algorithm does not match signing key.');
            }

            $base = $this->signatureBase($request, $components, $signatureParams, $headers);
            $publicKeyPem = $key->publicKeyPem ?? null;
            if ($publicKeyPem === null || $publicKeyPem === '') {
                throw new SignatureException('Public key PEM is not available for signature verification.');
            }

            $opensslAlgorithm = $this->opensslAlgorithm($key->algorithm);
            $result = openssl_verify($base, $signature, $publicKeyPem, $opensslAlgorithm);
            if ($result !== 1) {
                throw new SignatureException('Request signature verification failed.');
            }

            $replayChecked = false;
            if ($this->replayGuard !== null) {
                $scope = parse_url($request->absoluteUri, PHP_URL_HOST);
                if (! is_string($scope) || $scope === '') {
                    $scope = 'unknown:' . sha1($request->absoluteUri);
                }

                $this->replayGuard->rememberOrThrow($scope, $kid, $signature, $created);
                $replayChecked = true;
            }

            return new SignatureVerificationResult(true, $kid, $key->algorithm, $created, $expires, true, $replayChecked);
        } catch (SignatureException $exception) {
            return new SignatureVerificationResult(false, failureReason: $exception->getMessage(), contentDigestVerified: $contentDigestVerified);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>, 2: string}
     */
    private function parseSignatureInput(string $signatureInput): array
    {
        if (preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]*)=\(([^)]*)\)(.*)$/', $signatureInput, $matches) !== 1) {
            throw new SignatureException('Signature-Input header is malformed.');
        }

        // Everything after the label, byte for byte as received. The @signature-params line of
        // the base has to reproduce it exactly: re-serialising from the parsed parameters would
        // reorder them, drop the ones this implementation does not read, and normalise
        // whitespace -- and any of those changes the base, so a correctly signed request would
        // fail to verify.
        $signatureParams = '(' . $matches[2] . ')' . $matches[3];
        $components = $this->parseCoveredComponents($matches[2]);

        $parts = [];
        $parts['@label'] = $matches[1];

        foreach (explode(';', $matches[3]) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $segment, 2), 2, null);
            if ($name === null || $value === null) {
                continue;
            }

            $parts[$name] = trim($value, '"');
        }

        return [$parts, $components, $signatureParams];
    }

    /**
     * @return list<string>
     */
    private function parseCoveredComponents(string $inner): array
    {
        $inner = trim($inner);
        if ($inner === '') {
            throw new SignatureException('Signature-Input covers no components.');
        }

        if (preg_match_all('/"([^"]*)"(\S*)/', $inner, $matches, PREG_SET_ORDER) === false) {
            throw new SignatureException('Signature-Input covered component list is malformed.');
        }

        $components = [];
        $reserialized = [];
        foreach ($matches as $match) {
            $components[] = $match[1] . $match[2];
            $reserialized[] = '"' . $match[1] . '"' . $match[2];
        }

        // Anything the pattern did not consume would be silently ignored, and an ignored token is
        // an uncovered component treated as covered. Compare against the input to refuse instead.
        if (implode(' ', $reserialized) !== (string) preg_replace('/\s+/', ' ', $inner)) {
            throw new SignatureException('Signature-Input covered component list is malformed.');
        }

        return $components;
    }

    /**
     * @param list<PublicSigningKey> $keys
     */
    private function resolveKey(array $keys, string $kid): PublicSigningKey
    {
        $matches = array_values(array_filter($keys, static fn (PublicSigningKey $key): bool => $key->kid === $kid));
        if (count($matches) !== 1) {
            throw new SignatureException(count($matches) === 0 ? 'Signing key was not found.' : 'Duplicate signing keys found for kid.');
        }

        return $matches[0];
    }

    private function extractSignatureValue(string $signatureHeader, string $label): string
    {
        $pattern = sprintf('/(?:^|,)\\s*%s=:([^:]+):/', preg_quote($label, '/'));
        if (preg_match($pattern, $signatureHeader, $matches) !== 1) {
            throw new SignatureException('Signature header is malformed.');
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false) {
            throw new SignatureException('Signature value is not valid base64.');
        }

        return $decoded;
    }

    /**
     * @param list<string> $components
     * @param array<string, string> $headers
     */
    private function signatureBase(HttpRequest $request, array $components, string $signatureParams, array $headers): string
    {
        $lines = [];
        foreach ($components as $component) {
            $lines[] = sprintf('"%s": %s', $component, $this->componentResolver->resolve($request, $component, $headers));
        }

        $lines[] = sprintf('"@signature-params": %s', $signatureParams);

        return implode("\n", $lines);
    }

    private function assertSupportedAlgorithm(string $algorithm): void
    {
        if (! in_array($algorithm, self::SUPPORTED_ALGORITHMS, true)) {
            throw new SignatureException(sprintf('Unsupported signature algorithm "%s".', $algorithm));
        }
    }

    private function opensslAlgorithm(string $algorithm): int
    {
        return $algorithm === 'ES384' ? OPENSSL_ALGO_SHA384 : OPENSSL_ALGO_SHA256;
    }
}
