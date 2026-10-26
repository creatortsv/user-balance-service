<?php

declare(strict_types=1);

namespace App\Enum;

enum TransactionType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Hold = 'hold';
    case Capture = 'capture';
    case Release = 'release';
    case Transfer = 'transfer';
}
