<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum Transport: string
{
    case Rest = 'rest';
    case Mcp = 'mcp';
    case A2a = 'a2a';
    case Embedded = 'embedded';
}
