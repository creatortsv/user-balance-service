<?php

declare(strict_types=1);

namespace Tests\Integration\Service;

use App\Enum\RejectionReason;
use App\Event\OperationRejectedEvent;
use App\Exception\OperationRejectedException;
use App\Service\OperationRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Tests\DatabaseTestCase;

#[CoversClass(OperationRunner::class)]
#[CoversClass(OperationRejectedEvent::class)]
final class OperationRunnerTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';

    private OperationRunner $operationRunner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operationRunner = self::getContainer()->get(OperationRunner::class);
    }

    protected function tearDown(): void
    {
        unset($this->operationRunner);

        parent::tearDown();
    }

    public function testRollsBackRejectedOperation(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->operationRunner->run(
            self::OPERATION_ID,
            function (): void {
                $this->addAccounts(['user:1' => ['10.00', 3]]);

                throw new OperationRejectedException(RejectionReason::InsufficientFunds);
            },
        );

        self::assertSame(
            [
                'accounts' => [],
                'events' => [
                    [
                        'type' => 'balance.rejected',
                        'body' => [
                            'operationId' => self::OPERATION_ID,
                            'reason' => 'insufficient_funds',
                            'occurredAt' => '2026-10-24T12:00:00+00:00',
                        ],
                    ],
                ],
            ],
            [
                'accounts' => $this->getAccounts(),
                'events' => $this->getOutboxEvents(),
            ],
        );
    }

    public function testRollsBackFailedOperation(): void
    {
        try {
            $this->operationRunner->run(
                self::OPERATION_ID,
                function (): void {
                    $this->addAccounts(['user:1' => ['10.00', 3]]);

                    throw new RuntimeException('Some error.');
                },
            );
        } catch (RuntimeException $exception) {
        }

        self::assertSame(
            ['error' => 'Some error.', 'accounts' => [], 'events' => []],
            [
                'error' => $exception->getMessage(),
                'accounts' => $this->getAccounts(),
                'events' => $this->getOutboxEvents(),
            ],
        );
    }
}
