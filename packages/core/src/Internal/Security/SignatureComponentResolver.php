<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;
use Ucp\Sdk\Model\Http\HttpRequest;

/**
 * Resolves one RFC 9421 covered component to the value that goes into the signature base.
 *
 * A verifier has to rebuild the base from the components the *peer* said it covered, in the
 * order the peer listed them. Anything else verifies a different message than the one that was
 * signed, so this refuses whatever it cannot resolve rather than omitting it: a base that
 * silently drops a covered component still produces a signature that either matches or does
 * not, and when it matches, it confirms nothing about the dropped component.
 *
 * @internal
 */
final class SignatureComponentResolver
{
    /**
     * @param array<string, string> $headers Lowercased header names. When signing, the
     *                                       Content-Digest about to be sent is overlaid here,
     *                                       because it is not yet on the request.
     */
    public function resolve(HttpRequest $request, string $component, array $headers): string
    {
        if ($component === '') {
            throw new SignatureException('Signature-Input covers a component with an empty name.');
        }

        // Parameterised components (@query-param;name=..., a field with ;sf or ;key=...) change
        // what the value means. Treating "foo;key=bar" as the field "foo" would sign or accept
        // the wrong bytes, so they are refused until they are actually implemented.
        if (str_contains($component, ';')) {
            throw new SignatureException(sprintf(
                'Signature component "%s" carries parameters, which this implementation does not support.',
                $component,
            ));
        }

        if (! str_starts_with($component, '@')) {
            return $this->field($component, $headers);
        }

        return match ($component) {
            '@method' => strtoupper($request->method),
            '@target-uri' => $request->absoluteUri,
            '@authority' => $this->authority($request),
            '@scheme' => strtolower($this->uriPart($request, PHP_URL_SCHEME, '@scheme')),
            '@path' => $this->path($request),
            '@query' => $this->query($request),
            '@request-target' => $this->requestTarget($request),
            default => throw new SignatureException(sprintf('Unsupported signature component "%s".', $component)),
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function field(string $name, array $headers): string
    {
        if (! array_key_exists($name, $headers)) {
            throw new SignatureException(sprintf(
                'Signature covers header "%s", which the request does not carry.',
                $name,
            ));
        }

        // RFC 9421 section 2.1: the value is the field value with leading and trailing
        // whitespace removed.
        return trim($headers[$name]);
    }

    private function authority(HttpRequest $request): string
    {
        $host = strtolower($this->uriPart($request, PHP_URL_HOST, '@authority'));
        $port = parse_url($request->absoluteUri, PHP_URL_PORT);
        $scheme = strtolower((string) parse_url($request->absoluteUri, PHP_URL_SCHEME));

        // A default port is not part of the authority: https://x:443/ and https://x/ are the
        // same origin, and a peer that omits it must produce the same base we do.
        if (! is_int($port) || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $host;
        }

        return $host . ':' . $port;
    }

    private function path(HttpRequest $request): string
    {
        $path = parse_url($request->absoluteUri, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function query(HttpRequest $request): string
    {
        // RFC 9421 section 2.2.7: an absent query string is the leading '?' alone.
        return '?' . (string) $this->rawQuery($request);
    }

    private function requestTarget(HttpRequest $request): string
    {
        $query = $this->rawQuery($request);

        return $this->path($request) . ($query === null ? '' : '?' . $query);
    }

    private function rawQuery(HttpRequest $request): ?string
    {
        $query = parse_url($request->absoluteUri, PHP_URL_QUERY);

        return is_string($query) ? $query : null;
    }

    private function uriPart(HttpRequest $request, int $part, string $component): string
    {
        $value = parse_url($request->absoluteUri, $part);
        if (! is_string($value) || $value === '') {
            throw new SignatureException(sprintf(
                'Signature covers "%s", but the request URI "%s" has no such part.',
                $component,
                $request->absoluteUri,
            ));
        }

        return $value;
    }
}
