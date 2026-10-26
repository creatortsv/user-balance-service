<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

final class CurrencyScale extends Constraint
{
    public const string TOO_MANY_DECIMALS_ERROR = '2a5a47fc-0a28-4919-a30c-91e88fde14f6';

    protected const array ERROR_NAMES = [
        self::TOO_MANY_DECIMALS_ERROR => 'TOO_MANY_DECIMALS_ERROR',
    ];

    public string $message = 'This value should have {{ scale }} decimals or fewer.';
}
