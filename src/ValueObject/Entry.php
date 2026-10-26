<?php

declare(strict_types=1);

namespace App\ValueObject;

use BcMath\Number;

final readonly class Entry
{
    public function __construct(
        public Account $account,
        public Number $amount,
    ) {
    }
}
