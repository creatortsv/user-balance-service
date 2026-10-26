<?php

declare(strict_types=1);

namespace App\ValueObject;

use BcMath\Number;

final readonly class Balance
{
    public function __construct(
        public int $userId,
        public Number $available,
        public int $availableVersion,
        public Number $held,
        public int $heldVersion,
    ) {
    }
}
