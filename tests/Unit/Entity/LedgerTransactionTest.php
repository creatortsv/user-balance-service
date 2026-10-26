<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use App\Entity\Hold;
use App\Entity\LedgerTransaction;
use App\Enum\TransactionType;
use App\ValueObject\Account;
use App\ValueObject\Entry;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LedgerTransaction::class)]
#[CoversClass(Entry::class)]
#[CoversClass(Account::class)]
#[CoversClass(Hold::class)]
final class LedgerTransactionTest extends TestCase
{
    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';
    private const string HOLD_ID = 'b0000000-0000-4000-8000-000000000002';

    public function testMovesCreditFromWorldToUser(): void
    {
        $transaction = LedgerTransaction::credit(self::OPERATION_ID, 1, 'EUR', new Number('10.00'));

        self::assertSame(
            [
                'type' => TransactionType::Credit,
                'currency' => 'EUR',
                'entries' => ['world' => '-10.00', 'user:1' => '10.00'],
            ],
            [
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'entries' => $this->getEntries($transaction),
            ],
        );
    }

    public function testMovesDebitFromUserToWorld(): void
    {
        $transaction = LedgerTransaction::debit(self::OPERATION_ID, 1, 'EUR', new Number('10.00'));

        self::assertSame(
            [
                'type' => TransactionType::Debit,
                'currency' => 'EUR',
                'entries' => ['user:1' => '-10.00', 'world' => '10.00'],
            ],
            [
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'entries' => $this->getEntries($transaction),
            ],
        );
    }

    public function testMovesHeldFundsFromUserToHoldAccount(): void
    {
        $transaction = LedgerTransaction::hold(self::OPERATION_ID, 1, 'EUR', new Number('10.00'));

        self::assertSame(
            [
                'type' => TransactionType::Hold,
                'currency' => 'EUR',
                'entries' => ['user:1' => '-10.00', 'user:1:hold' => '10.00'],
            ],
            [
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'entries' => $this->getEntries($transaction),
            ],
        );
    }

    public function testMovesCapturedHoldFromHoldAccountToWorld(): void
    {
        $hold = new Hold(self::HOLD_ID, 1, 'EUR', new Number('10.00'));
        $transaction = LedgerTransaction::capture(self::OPERATION_ID, $hold);

        self::assertSame(
            [
                'type' => TransactionType::Capture,
                'currency' => 'EUR',
                'entries' => ['user:1:hold' => '-10.00', 'world' => '10.00'],
            ],
            [
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'entries' => $this->getEntries($transaction),
            ],
        );
    }

    public function testMovesReleasedHoldBackToUser(): void
    {
        $hold = new Hold(self::HOLD_ID, 1, 'EUR', new Number('10.00'));
        $transaction = LedgerTransaction::release(self::OPERATION_ID, $hold);

        self::assertSame(
            [
                'type' => TransactionType::Release,
                'currency' => 'EUR',
                'entries' => ['user:1:hold' => '-10.00', 'user:1' => '10.00'],
            ],
            [
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'entries' => $this->getEntries($transaction),
            ],
        );
    }

    public function testMovesTransferFromSenderToReceiver(): void
    {
        $transaction = LedgerTransaction::transfer(self::OPERATION_ID, 1, 2, 'EUR', new Number('10.00'));

        self::assertSame(
            [
                'type' => TransactionType::Transfer,
                'currency' => 'EUR',
                'entries' => ['user:1' => '-10.00', 'user:2' => '10.00'],
            ],
            [
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'entries' => $this->getEntries($transaction),
            ],
        );
    }

    private function getEntries(LedgerTransaction $transaction): array
    {
        $entries = [];

        foreach ($transaction->entries() as $entry) {
            $entries[$entry->account->name] = $entry->amount->value;
        }

        return $entries;
    }
}
