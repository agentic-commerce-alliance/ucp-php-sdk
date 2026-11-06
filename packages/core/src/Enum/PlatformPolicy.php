<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

/**
 * Which agent platforms a business talks to.
 *
 * `Listed` is the gate this SDK always had: a profile host outside `allowed_profile_hosts` is
 * refused, and an empty list refuses every remote platform. `All` is what the specification
 * describes as the default: any platform with a discoverable profile may interact without
 * prior registration, and the list becomes the registry of pre-approved platforms that are
 * served without going through discovery. The fetch-safety rules apply in both modes.
 */
enum PlatformPolicy: string
{
    case Listed = 'listed';
    case All = 'all';
}
