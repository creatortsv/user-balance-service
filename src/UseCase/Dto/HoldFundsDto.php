<?php

declare(strict_types=1);

namespace App\UseCase\Dto;

use BcMath\Number;

final readonly class HoldFundsDto
{
    public function __construct(
        public string $operationId,
        public int $userId,
        public string $currency,
        public Number $amount,
    ) {
    }
}
