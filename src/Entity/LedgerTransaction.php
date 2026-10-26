<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TransactionType;
use App\ValueObject\Account;
use App\ValueObject\Entry;
use BcMath\Number;

final readonly class LedgerTransaction
{
    private function __construct(
        public string $id,
        public TransactionType $type,
        public string $currency,
        public Account $from,
        public Account $to,
        public Number $amount,
    ) {
    }

    public static function credit(string $id, int $userId, string $currency, Number $amount): self
    {
        return new self($id, TransactionType::Credit, $currency, Account::world(), Account::user($userId), $amount);
    }

    public static function debit(string $id, int $userId, string $currency, Number $amount): self
    {
        return new self($id, TransactionType::Debit, $currency, Account::user($userId), Account::world(), $amount);
    }

    public static function hold(string $id, int $userId, string $currency, Number $amount): self
    {
        return new self($id, TransactionType::Hold, $currency, Account::user($userId), Account::hold($userId), $amount);
    }

    public static function capture(string $id, Hold $hold): self
    {
        return new self(
            $id,
            TransactionType::Capture,
            $hold->currency,
            Account::hold($hold->userId),
            Account::world(),
            $hold->amount,
        );
    }

    public static function release(string $id, Hold $hold): self
    {
        return new self(
            $id,
            TransactionType::Release,
            $hold->currency,
            Account::hold($hold->userId),
            Account::user($hold->userId),
            $hold->amount,
        );
    }

    public static function transfer(
        string $id,
        int $fromUserId,
        int $toUserId,
        string $currency,
        Number $amount,
    ): self {
        return new self(
            $id,
            TransactionType::Transfer,
            $currency,
            Account::user($fromUserId),
            Account::user($toUserId),
            $amount,
        );
    }

    /**
     * @return list<Entry>
     */
    public function entries(): array
    {
        // TODO: пока в транзакции одна проводка, а для операции с комиссией понадобится несколько
        return [
            new Entry($this->from, -$this->amount),
            new Entry($this->to, $this->amount),
        ];
    }
}
