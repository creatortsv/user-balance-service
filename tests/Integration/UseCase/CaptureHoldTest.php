<?php

declare(strict_types=1);

namespace Tests\Integration\UseCase;

use App\Entity\AccountBalance;
use App\Entity\Hold;
use App\Event\HoldCapturedEvent;
use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use App\Repository\AccountRepository;
use App\Repository\HoldRepository;
use App\Repository\LedgerTransactionRepository;
use App\Repository\Row;
use App\Service\CurrencyService;
use App\Service\HoldService;
use App\Service\LedgerService;
use App\UseCase\CaptureHold;
use App\UseCase\Dto\CaptureHoldDto;
use App\ValueObject\Balance;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Tests\DatabaseTestCase;

#[CoversClass(CaptureHold::class)]
#[CoversClass(CaptureHoldDto::class)]
#[CoversClass(HoldCapturedEvent::class)]
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
final class CaptureHoldTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';
    private const string HOLD_ID = 'b0000000-0000-4000-8000-000000000002';

    private CaptureHold $captureHold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureHold = self::getContainer()->get(CaptureHold::class);
    }

    protected function tearDown(): void
    {
        unset($this->captureHold);

        parent::tearDown();
    }

    public function testCapturesHeldFunds(): void
    {
        $this->addAccounts(['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 5]]);

        $this->addHold(self::HOLD_ID, 1, '2.50', 'active', '2026-10-24 11:00:00+00');

        $this->captureHold->execute(new CaptureHoldDto(self::OPERATION_ID, self::HOLD_ID));

        self::assertSame(
            [
                'accounts' => ['user:1' => ['10.00', 3], 'user:1:hold' => ['2.50', 6]],
                'holds' => [self::HOLD_ID => ['2.50', 'captured']],
                'ledger' => ['user:1:hold' => '-2.50', 'world' => '2.50'],
                'events' => [
                    [
                        'type' => 'balance.captured',
                        'balance' => [
                            'userId' => 1,
                            'available' => '10.00',
                            'availableVersion' => 3,
                            'held' => '2.50',
                            'heldVersion' => 6,
                        ],
                    ],
                ],
            ],
            [
                'accounts' => $this->getAccounts(),
                'holds' => $this->getHolds(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }

    public function testStoresLedgerTransaction(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->addAccounts(['user:1:hold' => ['2.50', 2]]);

        $this->addHold(self::HOLD_ID, 1, '2.50', 'active', '2026-10-24 11:00:00+00');

        $this->captureHold->execute(new CaptureHoldDto(self::OPERATION_ID, self::HOLD_ID));

        self::assertSame(
            [
                [
                    'id' => self::HOLD_ID,
                    'type' => 'hold',
                    'currency' => 'EUR',
                    'created_at' => '2026-10-24 11:00:00+00',
                ],
                [
                    'id' => self::OPERATION_ID,
                    'type' => 'capture',
                    'currency' => 'EUR',
                    'created_at' => '2026-10-24 12:00:00+00',
                ],
            ],
            $this->getLedgerTransactions(),
        );
    }

    public function testLocksHoldBeforeAccount(): void
    {
        $this->addAccounts(['user:1:hold' => ['2.50', 2]]);

        $this->addHold(self::HOLD_ID, 1, '2.50', 'active', '2026-10-24 11:00:00+00');

        $this->clearQueryLog();

        $this->captureHold->execute(new CaptureHoldDto(self::OPERATION_ID, self::HOLD_ID));

        self::assertSame([self::HOLD_ID, 'user:1:hold'], $this->getLockedKeys());
    }

    public function testRejectsCaptureOfUnknownHold(): void
    {
        $this->captureHold->execute(new CaptureHoldDto(self::OPERATION_ID, self::HOLD_ID));

        self::assertSame(
            [
                'holds' => [],
                'ledger' => [],
                'events' => [['type' => 'balance.rejected', 'reason' => 'hold_not_found']],
            ],
            [
                'holds' => $this->getHolds(),
                'ledger' => $this->getLedgerEntries(),
                'events' => $this->getOutboxEventOutcomes(),
            ],
        );
    }
}
