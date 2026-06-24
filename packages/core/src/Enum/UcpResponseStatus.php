<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum UcpResponseStatus: string
{
    case Success = 'success';
    case Processing = 'processing';
    case Error = 'error';
}
