<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum AccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Closed = 'closed';
}
