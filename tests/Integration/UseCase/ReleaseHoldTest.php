<?php

declare(strict_types=1);

namespace Tests\Integration\UseCase;

use App\Entity\AccountBalance;
use App\Entity\Hold;
use App\Event\HoldReleasedEvent;
use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use App\Repository\AccountRepository;
use App\Repository\HoldRepository;
use App\Repository\LedgerTransactionRepository;
use App\Repository\Row;
use App\Service\CurrencyService;
use App\Service\HoldService;
use App\Service\LedgerService;
use App\UseCase\Dto\ReleaseHoldDto;
use App\UseCase\ReleaseHold;
use App\ValueObject\Balance;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Tests\DatabaseTestCase;

#[CoversClass(ReleaseHold::class)]
#[CoversClass(ReleaseHoldDto::class)]
#[CoversClass(HoldReleasedEvent::class)]
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
final class ReleaseHoldTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';
    private const string HOLD_ID = 'b0000000-0000-4000-8000-000000000002';

    private ReleaseHold $releaseHold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releaseHold = self::getContainer()->get(ReleaseHold::class);
    }

    protected function tearDown(): void
    {
        unset($this->releaseHold);

        parent::tearDown();
    }

    public function testReleasesHeldFunds(): void
    {
        $this->addAccounts(['user:1' => ['10.00', 3], 'user:1:hold' => ['5.00', 2]]);

        $this->addHold(self::HOLD_ID, 1, '2.50', 'active', '2026-10-24 11:00:00+00');

        $this->releaseHold->execute(new ReleaseHoldDto(self::OPERATION_ID, self::HOLD_ID));

        self::assertSame(
            [
                'accounts' => ['user:1' => ['12.50', 4], 'user:1:hold' => ['2.50', 3]],
                'holds' => [self::HOLD_ID => ['2.50', 'released']],
                'ledger' => ['user:1:hold' => '-2.50', 'user:1' => '2.50'],
                'events' => [
                    [
                        'type' => 'balance.released',
                        'balance' => [
                            'userId' => 1,
                            'available' => '12.50',
                            'availableVersion' => 4,
                            'held' => '2.50',
                            'heldVersion' => 3,
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

        $this->releaseHold->execute(new ReleaseHoldDto(self::OPERATION_ID, self::HOLD_ID));

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
                    'type' => 'release',
                    'currency' => 'EUR',
                    'created_at' => '2026-10-24 12:00:00+00',
                ],
            ],
            $this->getLedgerTransactions(),
        );
    }

    public function testRejectsReleaseOfUnknownHold(): void
    {
        $this->releaseHold->execute(new ReleaseHoldDto(self::OPERATION_ID, self::HOLD_ID));

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
