<?php

declare(strict_types=1);

namespace Tests\Integration\UseCase;

use App\Entity\AccountBalance;
use App\Event\BalanceCreditedEvent;
use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use App\Repository\AccountRepository;
use App\Repository\LedgerTransactionRepository;
use App\Repository\Row;
use App\Service\CurrencyService;
use App\Service\LedgerService;
use App\UseCase\CreditBalance;
use App\UseCase\Dto\CreditBalanceDto;
use App\ValueObject\Balance;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Tests\DatabaseTestCase;

#[CoversClass(CreditBalance::class)]
#[CoversClass(CreditBalanceDto::class)]
#[CoversClass(BalanceCreditedEvent::class)]
#[CoversClass(OperationRejectedEvent::class)]
#[CoversClass(LedgerService::class)]
#[CoversClass(CurrencyService::class)]
#[CoversClass(AccountRepository::class)]
#[CoversClass(AccountBalance::class)]
#[CoversClass(LedgerTransactionRepository::class)]
#[CoversClass(Row::class)]
#[CoversClass(OperationRejectedException::class)]
#[CoversClass(Balance::class)]
final class CreditBalanceTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';

    private CreditBalance $creditBalance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creditBalance = self::getContainer()->get(CreditBalance::class);
    }

    protected function tearDown(): void
    {
        unset($this->creditBalance);

        parent::tearDown();
    }

    #[DataProvider('creditsBalanceDataProvider')]
    public function testCreditsBalance(
        array $accounts,
        string $amount,
        array $expectedAccounts,
        array $expectedLedger,
        array $expectedBalance,
    ): void {
        $this->addAccounts($accounts);

        $this->creditBalance->execute(new CreditBalanceDto(self::OPERATION_ID, 1, 'EUR', new Number($amount)));

        self::assertSame(
            [
                'accounts' => $expectedAccounts,
                'ledger' => $expectedLedger,
                'events' => [['type' => 'balance.credited', 'balance' => $expectedBalance]],
            ],
            [
                'accounts' => $this->getAccounts(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public static function creditsBalanceDataProvider(): iterable
    {
        yield 'first credit opens the account' => [
            'accounts' => [],
            'amount' => '10.00',
            'expectedAccounts' => ['user:1' => ['10.00', 1]],
            'expectedLedger' => ['world' => '-10.00', 'user:1' => '10.00'],
            'expectedBalance' => [
                'userId' => 1,
                'available' => '10.00',
                'availableVersion' => 1,
                'held' => '0.00',
                'heldVersion' => 0,
            ],
        ];

        yield 'credit adds to the balance' => [
            'accounts' => ['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 2]],
            'amount' => '2.50',
            'expectedAccounts' => ['user:1' => ['12.50', 4], 'user:1:hold' => ['5.00', 2]],
            'expectedLedger' => ['world' => '-2.50', 'user:1' => '2.50'],
            'expectedBalance' => [
                'userId' => 1,
                'available' => '12.50',
                'availableVersion' => 4,
                'held' => '5.00',
                'heldVersion' => 2,
            ],
        ];
    }

    public function testStoresLedgerTransaction(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->creditBalance->execute(new CreditBalanceDto(self::OPERATION_ID, 1, 'EUR', new Number('10.00')));

        self::assertSame(
            [
                [
                    'id' => self::OPERATION_ID,
                    'type' => 'credit',
                    'currency' => 'EUR',
                    'created_at' => '2026-10-24 12:00:00+00',
                ],
            ],
            $this->getLedgerTransactions(),
        );
    }

    public function testRejectsCreditAboveLimit(): void
    {
        $this->creditBalance->execute(new CreditBalanceDto(self::OPERATION_ID, 1, 'EUR', new Number('100000.01')));

        self::assertSame(
            [
                'accounts' => [],
                'ledger' => [],
                'events' => [['type' => 'balance.rejected', 'reason' => 'limit_exceeded']],
            ],
            [
                'accounts' => $this->getAccounts(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }
}
