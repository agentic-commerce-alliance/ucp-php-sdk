<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

use Ucp\Sdk\Exception\SignatureException;

/** @internal */
final class ContentDigestService
{
    public function create(string $body): string
    {
        return 'sha-256=:' . base64_encode(hash('sha256', $body, true)) . ':';
    }

    public function verify(string $body, ?string $digest): void
    {
        if ($digest === null || $digest === '') {
            throw new SignatureException('Missing Content-Digest header.');
        }

        if (! hash_equals($this->create($body), $digest)) {
            throw new SignatureException('Invalid Content-Digest header.');
        }
    }
}
