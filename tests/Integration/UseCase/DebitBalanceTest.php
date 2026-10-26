<?php

declare(strict_types=1);

namespace Tests\Integration\UseCase;

use App\Entity\AccountBalance;
use App\Event\BalanceDebitedEvent;
use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use App\Repository\AccountRepository;
use App\Repository\LedgerTransactionRepository;
use App\Repository\Row;
use App\Service\CurrencyService;
use App\Service\LedgerService;
use App\UseCase\DebitBalance;
use App\UseCase\Dto\DebitBalanceDto;
use App\ValueObject\Balance;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

#[CoversClass(DebitBalance::class)]
#[CoversClass(DebitBalanceDto::class)]
#[CoversClass(BalanceDebitedEvent::class)]
#[CoversClass(OperationRejectedEvent::class)]
#[CoversClass(LedgerService::class)]
#[CoversClass(CurrencyService::class)]
#[CoversClass(AccountRepository::class)]
#[CoversClass(AccountBalance::class)]
#[CoversClass(LedgerTransactionRepository::class)]
#[CoversClass(Row::class)]
#[CoversClass(OperationRejectedException::class)]
#[CoversClass(Balance::class)]
final class DebitBalanceTest extends DatabaseTestCase
{
    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';

    private DebitBalance $debitBalance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debitBalance = self::getContainer()->get(DebitBalance::class);
    }

    protected function tearDown(): void
    {
        unset($this->debitBalance);

        parent::tearDown();
    }

    #[DataProvider('debitsBalanceDataProvider')]
    public function testDebitsBalance(
        string $amount,
        array $expectedAccounts,
        array $expectedLedger,
        array $expectedBalance,
    ): void {
        $this->addAccounts(['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 2]]);

        $this->debitBalance->execute(new DebitBalanceDto(self::OPERATION_ID, 1, 'EUR', new Number($amount)));

        self::assertSame(
            [
                'accounts' => $expectedAccounts,
                'ledger' => $expectedLedger,
                'events' => [['type' => 'balance.debited', 'balance' => $expectedBalance]],
            ],
            [
                'accounts' => $this->getAccounts(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public static function debitsBalanceDataProvider(): iterable
    {
        yield 'part of the funds' => [
            'amount' => '2.50',
            'expectedAccounts' => ['user:1' => ['7.50', 4], 'user:1:hold' => ['5.00', 2]],
            'expectedLedger' => ['user:1' => '-2.50', 'world' => '2.50'],
            'expectedBalance' => [
                'userId' => 1,
                'available' => '7.50',
                'availableVersion' => 4,
                'held' => '5.00',
                'heldVersion' => 2,
            ],
        ];

        yield 'all available funds' => [
            'amount' => '10.00',
            'expectedAccounts' => ['user:1' => ['0.00', 4], 'user:1:hold' => ['5.00', 2]],
            'expectedLedger' => ['user:1' => '-10.00', 'world' => '10.00'],
            'expectedBalance' => [
                'userId' => 1,
                'available' => '0.00',
                'availableVersion' => 4,
                'held' => '5.00',
                'heldVersion' => 2,
            ],
        ];
    }

    #[DataProvider('rejectsDebitDataProvider')]
    public function testRejectsDebit(
        array $accounts,
        string $amount,
        array $expectedAccounts,
        string $expectedReason,
    ): void {
        $this->addAccounts($accounts);

        $this->debitBalance->execute(new DebitBalanceDto(self::OPERATION_ID, 1, 'EUR', new Number($amount)));

        self::assertSame(
            [
                'accounts' => $expectedAccounts,
                'ledger' => [],
                'events' => [['type' => 'balance.rejected', 'reason' => $expectedReason]],
            ],
            [
                'accounts' => $this->getAccounts(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public static function rejectsDebitDataProvider(): iterable
    {
        yield 'held funds' => [
            'accounts' => ['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 2]],
            'amount' => '10.01',
            'expectedAccounts' => ['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 2]],
            'expectedReason' => 'insufficient_funds',
        ];

        yield 'unknown account' => [
            'accounts' => [],
            'amount' => '2.50',
            'expectedAccounts' => [],
            'expectedReason' => 'account_not_found',
        ];
    }
}
