<?php

declare(strict_types=1);

namespace Ucp\Sdk\Enum;

enum AdjustmentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
