<?php

declare(strict_types=1);

namespace App\Enum;

enum RejectionReason: string
{
    case AccountNotFound = 'account_not_found';
    case InsufficientFunds = 'insufficient_funds';
    case HoldNotFound = 'hold_not_found';
    case HoldNotActive = 'hold_not_active';
    case LimitExceeded = 'limit_exceeded';
}
