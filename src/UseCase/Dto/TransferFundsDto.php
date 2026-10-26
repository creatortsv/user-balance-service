<?php

declare(strict_types=1);

namespace App\UseCase\Dto;

use BcMath\Number;

final readonly class TransferFundsDto
{
    public function __construct(
        public string $operationId,
        public int $fromUserId,
        public int $toUserId,
        public string $currency,
        public Number $amount,
    ) {
    }
}
