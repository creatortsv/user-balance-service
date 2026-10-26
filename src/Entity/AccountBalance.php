<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RejectionReason;
use App\Exception\OperationRejectedException;
use App\ValueObject\Account;
use BcMath\Number;

final readonly class AccountBalance
{
    public function __construct(
        public Account $account,
        public Number $balance,
        public string $currency,
        public int $version,
    ) {
    }

    public static function open(Account $account, string $currency): self
    {
        return new self(
            $account,
            new Number(0),
            $currency,
            0,
        );
    }

    public function post(Number $amount): self
    {
        $balance = $this->balance + $amount;

        if ($balance < 0) {
            throw new OperationRejectedException(RejectionReason::InsufficientFunds);
        }

        return new self(
            $this->account,
            $balance,
            $this->currency,
            $this->version + 1,
        );
    }
}
