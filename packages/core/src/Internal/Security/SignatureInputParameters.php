<?php

declare(strict_types=1);

namespace Ucp\Sdk\Internal\Security;

/**
 * The parameters of one `Signature-Input` entry, once they have been checked.
 *
 * @internal
 */
final class SignatureInputParameters
{
    public function __construct(
        public readonly string $label,
        public readonly string $kid,
        public readonly int $created,
        public readonly ?int $expires,
        public readonly ?string $requestedAlgorithm,
        public readonly ?string $tag,
    ) {
    }
}
