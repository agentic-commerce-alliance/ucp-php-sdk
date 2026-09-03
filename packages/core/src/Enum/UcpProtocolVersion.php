<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum UcpProtocolVersion: string
{
    case V20260408 = '2026-04-08';

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
        return self::V20260408;
    }

    public static function isSupported(string $version): bool
    {
        return self::tryFrom($version) !== null;
    }

    /**
     * @return list<string>
     */
    public static function supportedVersions(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
