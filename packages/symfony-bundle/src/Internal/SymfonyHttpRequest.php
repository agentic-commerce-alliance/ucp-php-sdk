<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Internal;

use Symfony\Component\HttpFoundation\Request;
use Ucp\Sdk\Model\Http\HttpRequest;

/**
 * The body-less `HttpRequest` the transport controllers hand to runtime configuration
 * resolution and A2A context building.
 *
 * Multi-value headers are joined with `, ` and the query is sorted by name, with non-scalar
 * values JSON-encoded. The listeners build their own requests on purpose: they carry the body
 * or keep only the first header value, so they do not share this conversion.
 *
 * @internal
 */
final class SymfonyHttpRequest
{
    public static function withoutBody(Request $request): HttpRequest
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $value) {
            $headers[$name] = implode(', ', array_map(static fn (?string $entry): string => (string) $entry, $value));
        }

        $query = $request->query->all();
        ksort($query);

        return new HttpRequest(
            $request->getMethod(),
            TargetUri::of($request),
            $headers,
            array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
                $query,
            ),
            '',
        );
    }
}
