<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Http;

use Ucp\Sdk\Exception\ValidationException;

/**
 * The bound on a URI the SDK stores: the profile cache, the key-directory cache, the
 * negotiation session and the discovery backoff hold it in a 500-character column, and a
 * non-ASCII byte is not a URI. Webhook targets are not stored, so they are not bound here.
 *
 * @internal
 */
final class StoredUri
{
    public const MAX_LENGTH = 500;

    public static function assertStorable(string $uri, string $what): void
    {
        if (strlen($uri) > self::MAX_LENGTH || preg_match('/[^\\x21-\\x7e]/', $uri) === 1) {
            throw new ValidationException(sprintf('%s must be at most %d ASCII characters.', $what, self::MAX_LENGTH));
        }
    }
}
