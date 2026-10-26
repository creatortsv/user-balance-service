<?php

declare(strict_types=1);

namespace Tests\Integration\UseCase;

use App\Entity\AccountBalance;
use App\Event\FundsTransferredEvent;
use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use App\Repository\AccountRepository;
use App\Repository\LedgerTransactionRepository;
use App\Repository\Row;
use App\Service\CurrencyService;
use App\Service\LedgerService;
use App\UseCase\Dto\TransferFundsDto;
use App\UseCase\TransferFunds;
use App\ValueObject\Balance;
use BcMath\Number;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Tests\DatabaseTestCase;

#[CoversClass(TransferFunds::class)]
#[CoversClass(TransferFundsDto::class)]
#[CoversClass(FundsTransferredEvent::class)]
#[CoversClass(OperationRejectedEvent::class)]
#[CoversClass(LedgerService::class)]
#[CoversClass(CurrencyService::class)]
#[CoversClass(AccountRepository::class)]
#[CoversClass(AccountBalance::class)]
#[CoversClass(LedgerTransactionRepository::class)]
#[CoversClass(Row::class)]
#[CoversClass(OperationRejectedException::class)]
#[CoversClass(Balance::class)]
final class TransferFundsTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';

    private TransferFunds $transferFunds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transferFunds = self::getContainer()->get(TransferFunds::class);
    }

    protected function tearDown(): void
    {
        unset($this->transferFunds);

        parent::tearDown();
    }

    #[DataProvider('transfersFundsDataProvider')]
    public function testTransfersFunds(
        array $accounts,
        int $fromUserId,
        int $toUserId,
        array $expectedAccounts,
        array $expectedLedger,
        array $expectedFrom,
        array $expectedTo,
    ): void {
        $this->addAccounts($accounts);

        $this->transferFunds->execute(
            new TransferFundsDto(self::OPERATION_ID, $fromUserId, $toUserId, 'EUR', new Number('2.50')),
        );

        self::assertSame(
            [
                'accounts' => $expectedAccounts,
                'ledger' => $expectedLedger,
                'events' => [['type' => 'balance.transferred', 'from' => $expectedFrom, 'to' => $expectedTo]],
            ],
            [
                'accounts' => $this->getAccounts(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public static function transfersFundsDataProvider(): iterable
    {
        yield 'from user 1 to user 2' => [
            'accounts' => ['user:1' => ['10.00', 3], 'user:2' => ['1.00', 5]],
            'fromUserId' => 1,
            'toUserId' => 2,
            'expectedAccounts' => ['user:1' => ['7.50', 4], 'user:2' => ['3.50', 6]],
            'expectedLedger' => ['user:1' => '-2.50', 'user:2' => '2.50'],
            'expectedFrom' => [
                'userId' => 1,
                'available' => '7.50',
                'availableVersion' => 4,
                'held' => '0.00',
                'heldVersion' => 0,
            ],
            'expectedTo' => [
                'userId' => 2,
                'available' => '3.50',
                'availableVersion' => 6,
                'held' => '0.00',
                'heldVersion' => 0,
            ],
        ];

        yield 'from user 2 to user 1' => [
            'accounts' => ['user:1' => ['1.00', 5], 'user:2' => ['10.00', 3]],
            'fromUserId' => 2,
            'toUserId' => 1,
            'expectedAccounts' => ['user:1' => ['3.50', 6], 'user:2' => ['7.50', 4]],
            'expectedLedger' => ['user:2' => '-2.50', 'user:1' => '2.50'],
            'expectedFrom' => [
                'userId' => 2,
                'available' => '7.50',
                'availableVersion' => 4,
                'held' => '0.00',
                'heldVersion' => 0,
            ],
            'expectedTo' => [
                'userId' => 1,
                'available' => '3.50',
                'availableVersion' => 6,
                'held' => '0.00',
                'heldVersion' => 0,
            ],
        ];
    }

    public function testStoresLedgerTransaction(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->addAccounts(['user:1' => ['10.00', 3]]);

        $this->transferFunds->execute(new TransferFundsDto(self::OPERATION_ID, 1, 2, 'EUR', new Number('2.50')));

        self::assertSame(
            [
                [
                    'id' => self::OPERATION_ID,
                    'type' => 'transfer',
                    'currency' => 'EUR',
                    'created_at' => '2026-10-24 12:00:00+00',
                ],
            ],
            $this->getLedgerTransactions(),
        );
    }

    #[DataProvider('locksAccountsInOneOrderDataProvider')]
    public function testLocksAccountsInOneOrder(array $accounts, int $fromUserId, int $toUserId): void
    {
        $this->addAccounts($accounts);

        $this->clearQueryLog();

        $this->transferFunds->execute(
            new TransferFundsDto(self::OPERATION_ID, $fromUserId, $toUserId, 'EUR', new Number('2.50')),
        );

        self::assertSame(['user:1', 'user:2'], $this->getLockedKeys());
    }

    public static function locksAccountsInOneOrderDataProvider(): iterable
    {
        yield 'from user 1 to user 2' => [
            'accounts' => ['user:1' => ['10.00', 3]],
            'fromUserId' => 1,
            'toUserId' => 2,
        ];

        yield 'from user 2 to user 1' => [
            'accounts' => ['user:2' => ['10.00', 3]],
            'fromUserId' => 2,
            'toUserId' => 1,
        ];
    }

    #[DataProvider('rejectsTransferAboveAvailableFundsDataProvider')]
    public function testRejectsTransferAboveAvailableFunds(
        array $accounts,
        int $fromUserId,
        int $toUserId,
        array $expectedAccounts,
    ): void {
        $this->addAccounts($accounts);

        $this->transferFunds->execute(
            new TransferFundsDto(self::OPERATION_ID, $fromUserId, $toUserId, 'EUR', new Number('10.01')),
        );

        self::assertSame(
            [
                'accounts' => $expectedAccounts,
                'ledger' => [],
                'events' => [['type' => 'balance.rejected', 'reason' => 'insufficient_funds']],
            ],
            [
                'accounts' => $this->getAccounts(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public static function rejectsTransferAboveAvailableFundsDataProvider(): iterable
    {
        yield 'from user 1 to user 2' => [
            'accounts' => ['user:1' => ['10.00', 3]],
            'fromUserId' => 1,
            'toUserId' => 2,
            'expectedAccounts' => ['user:1' => ['10.00', 3]],
        ];

        yield 'from user 2 to user 1' => [
            'accounts' => ['user:2' => ['10.00', 3]],
            'fromUserId' => 2,
            'toUserId' => 1,
            'expectedAccounts' => ['user:2' => ['10.00', 3]],
        ];
    }

    #[DataProvider('rejectsTransferFromUnknownAccountDataProvider')]
    public function testRejectsTransferFromUnknownAccount(int $fromUserId, int $toUserId): void
    {
        $this->transferFunds->execute(
            new TransferFundsDto(self::OPERATION_ID, $fromUserId, $toUserId, 'EUR', new Number('2.50')),
        );

        self::assertSame(
            [
                'accounts' => [],
                'ledger' => [],
                'events' => [['type' => 'balance.rejected', 'reason' => 'account_not_found']],
            ],
            [
                'accounts' => $this->getAccounts(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public static function rejectsTransferFromUnknownAccountDataProvider(): iterable
    {
        yield 'from user 1 to user 2' => ['fromUserId' => 1, 'toUserId' => 2];

        yield 'from user 2 to user 1' => ['fromUserId' => 2, 'toUserId' => 1];
    }
}
