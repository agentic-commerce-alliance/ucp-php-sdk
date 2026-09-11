<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Enum\SignatureAlgorithm;
use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Http\HttpRequest;
use Ucp\Sdk\Model\Security\ManagedSigningKey;
use Ucp\Sdk\Model\Security\PublicSigningKey;
use Ucp\Sdk\Model\Security\SignatureVerificationResult;
use Ucp\Sdk\Service\AgentKeyDirectoryFetcherInterface;
use Ucp\Sdk\Service\RequestSignatureServiceInterface;
use Ucp\Sdk\Service\SignatureReplayGuardInterface;

/** @internal */
final class Rfc9421RequestSignatureService implements RequestSignatureServiceInterface
{
    private const DEFAULT_SIGNATURE_LABEL = 'sig';

    /** @var list<string> */
    private const SIGNED_COMPONENTS = ['@method', '@target-uri'];
    private const TAG_WEB_BOT_AUTH = 'web-bot-auth';

    /**
     * The tag on UCP's default signature shape, which every business must accept.
     *
     * Easy to miss, and this implementation did: `web-bot-auth` looks like the only tag
     * worth naming, so refusing everything else refused the baseline as well. `sig1` carries
     * no extra requirements -- it identifies the default shape rather than asking for
     * anything -- which is exactly why it needs listing here: the refusal below exists to
     * stop a tag whose requirements went unapplied, and this one has none.
     */
    private const TAG_DEFAULT = 'sig1';

    /** Tags whose requirements this implementation actually applies. */
    private const SUPPORTED_TAGS = [self::TAG_DEFAULT, self::TAG_WEB_BOT_AUTH];
    private const SIGNATURE_AGENT = 'signature-agent';
    private const CONTENT_DIGEST = 'content-digest';

    private readonly SignatureComponentResolver $componentResolver;
    private readonly EcdsaSignatureCodec $signatureCodec;
    private readonly Ed25519KeyCodec $ed25519Codec;
    private readonly JwkThumbprint $jwkThumbprint;

    public function __construct(
        private readonly ContentDigestService $contentDigestService,
        private readonly ?SignatureReplayGuardInterface $replayGuard = null,
        private readonly int $maxLifetimeSeconds = 300,
        ?SignatureComponentResolver $componentResolver = null,
        ?EcdsaSignatureCodec $signatureCodec = null,
        ?Ed25519KeyCodec $ed25519Codec = null,
        ?JwkThumbprint $jwkThumbprint = null,
        private readonly ?AgentKeyDirectoryFetcherInterface $agentKeyDirectoryFetcher = null,
    ) {
        $this->componentResolver = $componentResolver ?? new SignatureComponentResolver();
        $this->signatureCodec = $signatureCodec ?? new EcdsaSignatureCodec();
        $this->ed25519Codec = $ed25519Codec ?? new Ed25519KeyCodec();
        $this->jwkThumbprint = $jwkThumbprint ?? new JwkThumbprint();
    }

    /**
     * The `$tag` parameter is deliberately not on `RequestSignatureServiceInterface`: adding a
     * parameter to a published interface breaks every implementation of it, and emitting a tag
     * is a signer concern that no caller reaches through the interface. This SDK verifies
     * web-bot-auth signatures; it does not need to produce them through the abstraction.
     */
    public function sign(
        HttpRequest $request,
        ManagedSigningKey $key,
        ?int $created = null,
        ?int $expires = null,
        ?string $tag = null,
    ): array {
        $created ??= time();
        $expires ??= $created + $this->maxLifetimeSeconds;
        $algorithm = SignatureAlgorithm::fromIdentifier($key->algorithm);

        // Content-Digest is representation metadata (RFC 9530): a request with no
        // representation has none to describe, so a bodyless GET neither carries nor covers it.
        $hasBody = $request->body !== '';
        $components = $hasBody
            ? [...self::SIGNED_COMPONENTS, self::CONTENT_DIGEST]
            : self::SIGNED_COMPONENTS;
        $digest = $hasBody ? $this->contentDigestService->create($request->body) : null;
        $signatureParams = sprintf(
            '(%s);created=%d;expires=%d;keyid="%s";alg="%s"',
            implode(' ', array_map(static fn (string $component): string => '"' . $component . '"', $components)),
            $created,
            $expires,
            $key->kid,
            $algorithm->wireIdentifier(),
        );

        // The tag is a signature parameter, so it is covered: appending it afterwards would
        // change `@signature-params` and leave a signature that verifies against nothing.
        if ($tag !== null) {
            $signatureParams .= sprintf(';tag="%s"', $tag);
        }
        $signatureInput = self::DEFAULT_SIGNATURE_LABEL . '=' . $signatureParams;

        // The digest is not on the request yet, so overlay the one about to be sent.
        $headers = $this->normalizeHeaders($request->headers);
        if ($digest !== null) {
            $headers[self::CONTENT_DIGEST] = $digest;
        }

        $base = $this->signatureBase($request, $components, $signatureParams, $headers);
        $signature = '';
        $signature = $this->rawSignature($base, $key, $algorithm);

        $headers = [
            'Signature-Input' => $signatureInput,
            'Signature' => self::DEFAULT_SIGNATURE_LABEL . '=:' . base64_encode($signature) . ':',
        ];

        return $digest === null ? $headers : ['Content-Digest' => $digest, ...$headers];
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

            // `expires` is deliberately not required. The specification's default signature
            // shape carries `created` and `keyid` and nothing else; `expires` belongs to the
            // web-bot-auth shape. Demanding it refused every peer signing the way the spec
            // describes -- and said so with a message that named no parameter, so the peer
            // could not tell which one it was missing.
            //
            // This is the same class of defect as emitting DER signatures: self-consistent,
            // green against our own signer, and unable to talk to anyone else. Found by an
            // external spec-derived agent, which is the only kind of test that could.
            if ($kid === null || $created === null) {
                throw new SignatureException('Signature-Input must carry both keyid and created.');
            }

            if ($requestedAlgorithm !== null && SignatureAlgorithm::tryFromIdentifier($requestedAlgorithm) === null) {
                throw new SignatureException(sprintf('Unsupported signature algorithm "%s".', $requestedAlgorithm));
            }

            // `tag` says what the signature is for. An unrecognised one is refused rather than
            // ignored: a tag this implementation does not understand may carry requirements it
            // therefore has not applied, and verifying anyway would report a check as passed
            // that was never performed.
            $tag = $parts['tag'] ?? null;
            if ($tag !== null && ! in_array($tag, self::SUPPORTED_TAGS, true)) {
                throw new SignatureException(sprintf('Unsupported signature tag "%s".', $tag));
            }

            // Web-bot-auth points at the agent's own key directory. Fetching it is an outbound
            // request whose destination the caller chose, so it goes through the same allow-list
            // and address pinning as a platform profile -- and only when the tag asks for it,
            // because otherwise a `Signature-Agent` header alone would make every request an
            // outbound fetch.
            if ($tag === self::TAG_WEB_BOT_AUTH) {
                $keys = [...$keys, ...$this->agentDirectoryKeys($headers)];
            }

            if ($created > time() + 60) {
                throw new SignatureException('Signature created timestamp is too far in the future.');
            }

            if ($expires !== null) {
                if ($expires < time() - 60) {
                    throw new SignatureException('Signature has expired.');
                }

                if (($expires - $created) > $this->maxLifetimeSeconds) {
                    throw new SignatureException('Signature lifetime exceeds the allowed window.');
                }
            } elseif ($created < time() - $this->maxLifetimeSeconds) {
                // A signature naming no expiry is still not valid forever. Bounding its age by
                // the same window keeps the replay protection that requiring `expires` was
                // reaching for, without refusing the shape the spec defines. The nonce guard
                // below is the other half; this is what stops a captured signature being
                // replayable after the nonce record has been purged.
                throw new SignatureException('Signature is older than the allowed window.');
            }

            if (in_array(self::CONTENT_DIGEST, $components, true)) {
                $this->contentDigestService->verify($request->body, $digest);
                $contentDigestVerified = true;
            } elseif ($request->body !== '') {
                // The security half of making the digest conditional. Without this, stripping
                // content-digest from the covered list is a body-swap primitive: the signature
                // still verifies over method and target, and nothing has attested to the bytes.
                throw new SignatureException(
                    'Signature does not cover content-digest, so the request body is unattested.',
                );
            }
            $signature = $this->extractSignatureValue($signatureHeader, $label);
            $key = $this->resolveKey($keys, $kid);
            $algorithm = SignatureAlgorithm::fromIdentifier($key->algorithm);
            if ($requestedAlgorithm !== null && SignatureAlgorithm::tryFromIdentifier($requestedAlgorithm) !== $algorithm) {
                throw new SignatureException('Signature algorithm does not match signing key.');
            }

            $base = $this->signatureBase($request, $components, $signatureParams, $headers);
            $publicKeyPem = $key->publicKeyPem ?? null;
            if ($publicKeyPem === null || $publicKeyPem === '') {
                throw new SignatureException('Public key PEM is not available for signature verification.');
            }

            $result = $this->verifySignature($base, $signature, $publicKeyPem, $algorithm);
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

            // Was a hardcoded `true`, so every successful verification claimed the digest had
            // been checked -- harmless while it always had been, and an overstatement the moment
            // a bodyless request legitimately has nothing to digest.
            return new SignatureVerificationResult(true, $kid, $key->algorithm, $created, $expires, $contentDigestVerified, $replayChecked);
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
    /**
     * The keys published at the request's `Signature-Agent` URL.
     *
     * A missing header is not an error here: the tag says the signer is a web bot, and a bot
     * whose keys are already in the local set is a legitimate case. An unreachable directory is
     * also not fatal on its own -- the signature simply fails to resolve a key, and reports that
     * rather than reporting a network problem as a bad signature.
     *
     * @param array<string, string> $headers
     *
     * @return list<PublicSigningKey>
     */
    private function agentDirectoryKeys(array $headers): array
    {
        $header = $headers[self::SIGNATURE_AGENT] ?? null;
        if ($header === null || $this->agentKeyDirectoryFetcher === null) {
            return [];
        }

        // A structured-field string: `Signature-Agent: "https://agent.example/keys"`.
        $uri = trim(trim($header), '"');
        if ($uri === '') {
            throw new SignatureException('Signature-Agent header is empty.');
        }

        return $this->agentKeyDirectoryFetcher->fetch($uri)->keys;
    }

    /**
     * Finds the key a `keyid` names, by label or by RFC 7638 thumbprint.
     *
     * An operator's `kid` is a label: two businesses both calling their key `"default"` is not
     * a collision anyone detects, and a peer that renames a key breaks every signature it ever
     * sent. A thumbprint is derived from the key material, so it names the same key everywhere.
     * Web-bot-auth uses thumbprints as `keyid`, so both forms have to resolve -- and the label
     * is tried first because that is what existing peers send.
     *
     * @param list<PublicSigningKey> $keys
     */
    private function resolveKey(array $keys, string $kid): PublicSigningKey
    {
        $matches = array_values(array_filter($keys, static fn (PublicSigningKey $key): bool => $key->kid === $kid));

        if ($matches === []) {
            $matches = array_values(array_filter(
                $keys,
                fn (PublicSigningKey $key): bool => $this->thumbprintOf($key) === $kid,
            ));
        }

        if (count($matches) !== 1) {
            throw new SignatureException(count($matches) === 0 ? 'Signing key was not found.' : 'Duplicate signing keys found for kid.');
        }

        return $matches[0];
    }

    /**
     * A key whose material cannot be thumbprinted simply does not match one.
     *
     * The alternative -- letting the exception escape -- would turn one unusable key in a peer's
     * key set into a failed verification for every key in it, which is the behaviour 2026-08-25
     * explicitly forbids of a JWK Set reader.
     */
    private function thumbprintOf(PublicSigningKey $key): ?string
    {
        try {
            return $this->jwkThumbprint->compute($key->toJwk());
        } catch (SignatureException) {
            return null;
        }
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

    /**
     * Accepts the fixed-width form the spec requires, and DER for one transition release.
     *
     * Releases up to 0.0.5 emitted DER here, so a peer still running one of those keeps working
     * across the upgrade. Dispatch is on length rather than on the leading 0x30 byte: a raw
     * signature starts with 0x30 once every 256 signatures, which would misread a conformant
     * signature as DER and reject it. A DER signature that happens to be exactly the raw length
     * needs both halves to encode two bytes short, which is around one in 2^48.
     */
    private function toDer(string $signature, SignatureAlgorithm $algorithm): string
    {
        if (strlen($signature) === $algorithm->coordinateBytes() * 2) {
            return $this->signatureCodec->rawToDer($signature, $algorithm->coordinateBytes());
        }

        return $signature;
    }

    /**
     * The signature as it goes on the wire.
     *
     * Ed25519 is not routed through openssl: PHP's extension cannot use these keys, and the
     * signature RFC 8032 defines is already the 64 bytes the wire wants -- putting it through
     * the ECDSA codec would corrupt it rather than reformat it.
     */
    private function rawSignature(string $base, ManagedSigningKey $key, SignatureAlgorithm $algorithm): string
    {
        if ($algorithm === SignatureAlgorithm::Ed25519) {
            $secretKey = $this->ed25519Codec->secretKeyFromPem($key->privateKeyPem);
            if ($secretKey === '') {
                throw new SignatureException('Ed25519 signing key is empty.');
            }

            return sodium_crypto_sign_detached($base, $secretKey);
        }

        $signature = '';
        if (! openssl_sign($base, $signature, $key->privateKeyPem, $this->opensslAlgorithm($algorithm))) {
            throw new SignatureException('Unable to sign request.');
        }

        // openssl emits DER; the wire format is fixed-width r||s.
        return $this->signatureCodec->derToRaw($signature, $algorithm->coordinateBytes());
    }

    private function verifySignature(string $base, string $signature, string $publicKeyPem, SignatureAlgorithm $algorithm): int
    {
        if ($algorithm !== SignatureAlgorithm::Ed25519) {
            $result = openssl_verify($base, $this->toDer($signature, $algorithm), $publicKeyPem, $this->opensslAlgorithm($algorithm));

            // openssl reports an internal error as false, which is not a verdict on the
            // signature. -1 is what openssl_verify itself uses for that, and the caller already
            // treats anything other than 1 as unverified.
            return $result === false ? -1 : $result;
        }

        // A wrong-length signature is a failed verification, not an exception: a peer sending
        // one is not a configuration problem here, and throwing would turn a bad signature into
        // a different status code than a bad signature.
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 0;
        }

        try {
            $publicKey = $this->ed25519Codec->publicKeyFromPem($publicKeyPem);
        } catch (SignatureException) {
            return 0;
        }

        if ($publicKey === '') {
            return 0;
        }

        return sodium_crypto_sign_verify_detached($signature, $base, $publicKey) ? 1 : 0;
    }

    private function opensslAlgorithm(SignatureAlgorithm $algorithm): int
    {
        return match ($algorithm) {
            SignatureAlgorithm::Es256 => OPENSSL_ALGO_SHA256,
            SignatureAlgorithm::Es384 => OPENSSL_ALGO_SHA384,
            SignatureAlgorithm::Ed25519 => throw new SignatureException('Ed25519 does not use an openssl digest algorithm.'),
        };
    }
}
