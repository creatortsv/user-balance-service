<?php

declare(strict_types=1);

namespace Tests\Integration\UseCase;

use App\Entity\AccountBalance;
use App\Entity\Hold;
use App\Event\FundsHeldEvent;
use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use App\Repository\AccountRepository;
use App\Repository\HoldRepository;
use App\Repository\LedgerTransactionRepository;
use App\Repository\Row;
use App\Service\CurrencyService;
use App\Service\HoldService;
use App\Service\LedgerService;
use App\UseCase\Dto\HoldFundsDto;
use App\UseCase\HoldFunds;
use App\ValueObject\Balance;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

#[CoversClass(HoldFunds::class)]
#[CoversClass(HoldFundsDto::class)]
#[CoversClass(FundsHeldEvent::class)]
#[CoversClass(OperationRejectedEvent::class)]
#[CoversClass(LedgerService::class)]
#[CoversClass(CurrencyService::class)]
#[CoversClass(HoldService::class)]
#[CoversClass(AccountRepository::class)]
#[CoversClass(AccountBalance::class)]
#[CoversClass(HoldRepository::class)]
#[CoversClass(LedgerTransactionRepository::class)]
#[CoversClass(Row::class)]
#[CoversClass(OperationRejectedException::class)]
#[CoversClass(Hold::class)]
#[CoversClass(Balance::class)]
final class HoldFundsTest extends DatabaseTestCase
{
    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';

    private HoldFunds $holdFunds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->holdFunds = self::getContainer()->get(HoldFunds::class);
    }

    protected function tearDown(): void
    {
        unset($this->holdFunds);

        parent::tearDown();
    }

    #[DataProvider('holdsFundsDataProvider')]
    public function testHoldsFunds(
        string $amount,
        array $expectedAccounts,
        array $expectedHolds,
        array $expectedLedger,
        array $expectedBalance,
    ): void {
        $this->addAccounts(['user:1' => ['10.00', 3]]);

        $this->holdFunds->execute(new HoldFundsDto(self::OPERATION_ID, 1, 'EUR', new Number($amount)));

        self::assertSame(
            [
                'accounts' => $expectedAccounts,
                'holds' => $expectedHolds,
                'ledger' => $expectedLedger,
                'events' => [['type' => 'balance.held', 'balance' => $expectedBalance]],
            ],
            [
                'accounts' => $this->getAccounts(),
                'holds' => $this->getHolds(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public static function holdsFundsDataProvider(): iterable
    {
        yield 'part of the funds' => [
            'amount' => '2.50',
            'expectedAccounts' => ['user:1' => ['7.50', 4], 'user:1:hold' => ['2.50', 1]],
            'expectedHolds' => [self::OPERATION_ID => ['2.50', 'active']],
            'expectedLedger' => ['user:1' => '-2.50', 'user:1:hold' => '2.50'],
            'expectedBalance' => [
                'userId' => 1,
                'available' => '7.50',
                'availableVersion' => 4,
                'held' => '2.50',
                'heldVersion' => 1,
            ],
        ];

        yield 'all available funds' => [
            'amount' => '10.00',
            'expectedAccounts' => ['user:1' => ['0.00', 4], 'user:1:hold' => ['10.00', 1]],
            'expectedHolds' => [self::OPERATION_ID => ['10.00', 'active']],
            'expectedLedger' => ['user:1' => '-10.00', 'user:1:hold' => '10.00'],
            'expectedBalance' => [
                'userId' => 1,
                'available' => '0.00',
                'availableVersion' => 4,
                'held' => '10.00',
                'heldVersion' => 1,
            ],
        ];
    }

    public function testRejectsHoldOfHeldFunds(): void
    {
        $this->addAccounts(['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 2]]);

        $this->holdFunds->execute(new HoldFundsDto(self::OPERATION_ID, 1, 'EUR', new Number('10.01')));

        self::assertSame(
            [
                'accounts' => ['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 2]],
                'holds' => [],
                'ledger' => [],
                'events' => [['type' => 'balance.rejected', 'reason' => 'insufficient_funds']],
            ],
            [
                'accounts' => $this->getAccounts(),
                'holds' => $this->getHolds(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }
}
