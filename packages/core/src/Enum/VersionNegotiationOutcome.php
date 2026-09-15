<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

/**
 * What became of a platform's protocol version once this business had looked at it.
 */
enum VersionNegotiationOutcome: string
{
    /** The platform speaks the version this release serves; the operation went ahead. */
    case Accepted = 'accepted';

    /** The platform named a version this release does not serve and was refused with `version_unsupported`. */
    case Rejected = 'rejected';
}
