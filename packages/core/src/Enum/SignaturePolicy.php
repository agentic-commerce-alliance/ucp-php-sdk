<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum SignaturePolicy: string
{
    case Strict = 'strict';
    case Log = 'log';
    case Off = 'off';
}
