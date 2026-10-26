<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class SupportedCurrency extends Constraint
{
    public const string UNSUPPORTED_CURRENCY_ERROR = 'f7bf8c92-373e-4b6b-959f-c2ebb091bec5';

    protected const array ERROR_NAMES = [
        self::UNSUPPORTED_CURRENCY_ERROR => 'UNSUPPORTED_CURRENCY_ERROR',
    ];

    public string $message = 'This value should be one of {{ currencies }}.';
}
