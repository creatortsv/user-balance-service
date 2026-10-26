<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountBalance;
use App\Entity\LedgerTransaction;
use App\Enum\RejectionReason;
use App\Exception\OperationRejectedException;
use App\Repository\AccountRepository;
use App\Repository\LedgerTransactionRepository;
use App\ValueObject\Account;
use App\ValueObject\Balance;
use App\ValueObject\Entry;
use Symfony\Component\Clock\ClockInterface;

final readonly class LedgerService
{
    public function __construct(
        private AccountRepository $accounts,
        private LedgerTransactionRepository $transactions,
        private CurrencyService $currencies,
        private ClockInterface $clock,
    ) {
    }

    public function post(LedgerTransaction $transaction): void
    {
        $limit = $this->currencies->limit($transaction->currency, $transaction->type);

        if ($limit !== null && $transaction->amount > $limit) {
            throw new OperationRejectedException(RejectionReason::LimitExceeded);
        }

        $entriesInLockOrder = $transaction->entries();

        usort($entriesInLockOrder, static fn (Entry $a, Entry $b): int => $a->account->name <=> $b->account->name);

        foreach ($entriesInLockOrder as $entry) {
            if (!$entry->account->hasStoredBalance()) {
                continue;
            }

            if ($entry->amount > 0) {
                $this->accounts->addIfMissing(
                    AccountBalance::open(
                        $entry->account,
                        $transaction->currency,
                    ),
                );
            }

            $balance = $this->accounts->findForUpdate($entry->account, $transaction->currency)
                ?? throw new OperationRejectedException(RejectionReason::AccountNotFound);

            $this->accounts->save($balance->post($entry->amount));
        }

        // TODO: балансы в accounts никто не сверяет с суммами проводок, нужна сверка по расписанию
        $this->transactions->add($transaction, $this->clock->now());
    }

    public function balance(int $userId, string $currency): Balance
    {
        $available = $this->storedBalance(Account::user($userId), $currency);
        $held = $this->storedBalance(Account::hold($userId), $currency);

        return new Balance(
            $userId,
            $available->balance,
            $available->version,
            $held->balance,
            $held->version,
        );
    }

    private function storedBalance(Account $account, string $currency): AccountBalance
    {
        return $this->accounts->find($account, $currency) ?? AccountBalance::open($account, $currency);
    }
}
