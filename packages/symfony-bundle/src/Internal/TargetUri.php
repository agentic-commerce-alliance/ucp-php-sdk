<?php

declare(strict_types=1);

namespace Ucp\Sdk\Symfony\Internal;

use Symfony\Component\HttpFoundation\Request;

/**
 * The request's target URI as the peer sent it, for `HttpRequest::$absoluteUri`.
 *
 * That value is what RFC 9421 `@target-uri` resolves to, on request signatures and on the
 * `;req` binding of response signatures, so it has to be the URI the peer signed. Symfony's
 * `Request::getUri()` is not: it runs the query through `normalizeQueryString()`, which sorts
 * the parameters by name and re-encodes them, and every signature over a query that was not
 * already in that form failed to verify.
 *
 * This is `getUri()` with the raw `QUERY_STRING` in place of the normalised one. It does not
 * use `getRequestUri()` instead: a host application may rewrite `REQUEST_URI` to a resolved
 * path without the query, leaving the query only in `QUERY_STRING`, and the query would then
 * vanish from the signature base altogether.
 *
 * @internal
 */
final class TargetUri
{
    public static function of(Request $request): string
    {
        $query = $request->server->get('QUERY_STRING');

        return $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo()
            . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
