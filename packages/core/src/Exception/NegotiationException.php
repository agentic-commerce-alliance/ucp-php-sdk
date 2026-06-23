<?php

declare(strict_types=1);

namespace Ucp\Sdk\Exception;

final class NegotiationException extends UcpException
{
    private function __construct(
        string $message,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public static function versionUnsupported(string $message = 'Unsupported UCP profile version.'): self
    {
        return new self($message, 'version_unsupported');
    }

    public static function capabilitiesIncompatible(string $message = 'Requested operation is not included in the negotiated capability intersection.'): self
    {
        return new self($message, 'capabilities_incompatible');
    }
}
