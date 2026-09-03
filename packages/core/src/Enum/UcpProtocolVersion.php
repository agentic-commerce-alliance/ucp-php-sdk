<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum UcpProtocolVersion: string
{
    /**
     * Retained so persisted rows, historical profiles and the pinned schema tree still parse.
     * It is no longer servable -- see `supportedVersions()`.
     */
    case V20260408 = '2026-04-08';

    case V20260825 = '2026-08-25';

    /**
     * The protocol version this SDK release serves.
     *
     * Everything that has to name a version reads it here: the bundle's `version`
     * default, the response envelope, and the directory the generated schemas are
     * loaded from. Those three used to hardcode it independently, so configuring
     * `ucp_sdk.version` moved one of them and left the other two behind -- the SDK
     * advertised the configured version, validated against whatever schemas were
     * compiled in, and stamped a third value into every envelope. Nothing detected
     * the disagreement because each site was individually self-consistent.
     */
    public static function current(): self
    {
        return self::V20260825;
    }

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::supportedVersions(), true);
    }

    /**
     * The versions this release can serve, which is not the same as the versions it can name.
     *
     * Enum cases outlive servability. `V20260408` is kept -- removing a case is a
     * backwards-incompatible change, and persisted rows and the pinned tree still refer to it
     * -- but this SDK switches protocol version outright per release, with no runtime
     * multi-version support, so exactly one version is servable at a time. Deriving this from
     * `self::cases()` was indistinguishable from that while there was one case, and would have
     * quietly started accepting a version whose generated schemas are gone.
     *
     * @return list<string>
     */
    public static function supportedVersions(): array
    {
        return [self::current()->value];
    }

    /**
     * Every version this enum can name, servable or not.
     *
     * @return list<string>
     */
    public static function knownVersions(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
