<?php

declare(strict_types=1);

namespace Tests\Integration\Outbox;

use App\Enum\RejectionReason;
use App\Event\OperationRejectedEvent;
use App\Outbox\OutboxMessageStamp;
use App\Outbox\OutboxTransport;
use App\Outbox\OutboxTransportFactory;
use App\Repository\Row;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Tests\DatabaseTestCase;

#[CoversClass(OutboxTransport::class)]
#[CoversClass(OutboxTransportFactory::class)]
#[CoversClass(OutboxMessageStamp::class)]
#[CoversClass(Row::class)]
final class OutboxTransportTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string FIRST_OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';
    private const string SECOND_OPERATION_ID = 'b0000000-0000-4000-8000-000000000002';
    private const string THIRD_OPERATION_ID = 'c0000000-0000-4000-8000-000000000003';

    private TransportInterface $outbox;
    private MessageBusInterface $eventBus;
    private DebugDataHolder $debugDataHolder;
    private Connection $otherRelayConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = self::getContainer()->get('messenger.transport.outbox');
        $this->eventBus = self::getContainer()->get('event.bus');
        $this->debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $this->otherRelayConnection = DriverManager::getConnection($this->connection->getParams());
    }

    protected function tearDown(): void
    {
        $this->otherRelayConnection->close();

        unset($this->outbox, $this->eventBus, $this->debugDataHolder, $this->otherRelayConnection);

        parent::tearDown();
    }

    public function testStoresEventWithoutNotification(): void
    {
        $this->eventBus->dispatch(new OperationRejectedEvent(
            self::FIRST_OPERATION_ID,
            RejectionReason::InsufficientFunds,
            new DateTimeImmutable('2026-10-24 12:00:00'),
        ));

        self::assertSame(
            [
                'queries' => ['INSERT INTO outbox (type, body, retries, available_at) VALUES (?, ?, ?, ?)'],
                'events' => [
                    [
                        'type' => 'balance.rejected',
                        'body' => [
                            'operationId' => self::FIRST_OPERATION_ID,
                            'reason' => 'insufficient_funds',
                            'occurredAt' => '2026-10-24T12:00:00+00:00',
                        ],
                    ],
                ],
            ],
            [
                'queries' => array_column($this->debugDataHolder->getData()['default'], 'sql'),
                'events' => $this->getOutboxEvents(),
            ],
        );
    }

    public function testForgetsEventOfRolledBackTransaction(): void
    {
        $this->connection->beginTransaction();

        $this->sendEvent(self::FIRST_OPERATION_ID);

        $this->connection->rollBack();

        self::assertSame([], $this->getOutboxEvents());
    }

    public function testDeliversEventsOneByOneInOrderOfSending(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID);

        $this->sendEvent(self::SECOND_OPERATION_ID);

        $this->sendEvent(self::THIRD_OPERATION_ID);

        self::assertSame(
            [
                [self::FIRST_OPERATION_ID],
                [self::SECOND_OPERATION_ID],
                [self::THIRD_OPERATION_ID],
                [],
            ],
            [
                $this->receiveOperationIds(),
                $this->receiveOperationIds(),
                $this->receiveOperationIds(),
                $this->receiveOperationIds(),
            ],
        );
    }

    public function testDeliversOldestDueEventFirst(): void
    {
        $clock = self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID);

        $this->sendEvent(self::SECOND_OPERATION_ID);

        $receivedFirst = $this->receiveOperationIds();

        $clock->sleep(60);

        $receivedAfterTimeout = $this->receiveOperationIds();

        self::assertSame(
            ['first' => [self::FIRST_OPERATION_ID], 'afterTimeout' => [self::FIRST_OPERATION_ID]],
            ['first' => $receivedFirst, 'afterTimeout' => $receivedAfterTimeout],
        );
    }

    public function testSkipsEventThatAnotherRelayIsTaking(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID);

        $this->sendEvent(self::SECOND_OPERATION_ID);

        $this->otherRelayConnection->beginTransaction();

        $this->otherRelayConnection->executeQuery('SELECT id FROM outbox ORDER BY id LIMIT 1 FOR UPDATE');

        $this->connection->executeStatement("SET lock_timeout = '1s'");

        self::assertSame([self::SECOND_OPERATION_ID], $this->receiveOperationIds());
    }

    public function testHoldsBackDelayedEventUntilItIsDue(): void
    {
        $clock = self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID, new DelayStamp(3000));

        $clock->sleep(2);

        $receivedBeforeDue = $this->receiveOperationIds();

        $clock->sleep(1);

        $receivedWhenDue = $this->receiveOperationIds();

        self::assertSame(
            ['beforeDue' => [], 'whenDue' => [self::FIRST_OPERATION_ID]],
            ['beforeDue' => $receivedBeforeDue, 'whenDue' => $receivedWhenDue],
        );
    }

    public function testRedeliversEventTakenByStoppedRelay(): void
    {
        $clock = self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID);

        $receivedFirst = $this->receiveOperationIds();

        $clock->sleep(59);

        $receivedWhileTaken = $this->receiveOperationIds();

        $clock->sleep(1);

        $receivedAfterTimeout = $this->receiveOperationIds();

        self::assertSame(
            [
                'first' => [self::FIRST_OPERATION_ID],
                'whileTaken' => [],
                'afterTimeout' => [self::FIRST_OPERATION_ID],
            ],
            [
                'first' => $receivedFirst,
                'whileTaken' => $receivedWhileTaken,
                'afterTimeout' => $receivedAfterTimeout,
            ],
        );
    }

    public function testForgetsAcknowledgedEvent(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID);

        $this->sendEvent(self::SECOND_OPERATION_ID);

        [$envelope] = $this->outbox->get();

        $this->outbox->ack($envelope);

        self::assertSame([self::SECOND_OPERATION_ID], $this->getOperationIdsInOutbox());
    }

    public function testForgetsRejectedEvent(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID);

        $this->sendEvent(self::SECOND_OPERATION_ID);

        [$envelope] = $this->outbox->get();

        $this->outbox->reject($envelope);

        self::assertSame([self::SECOND_OPERATION_ID], $this->getOperationIdsInOutbox());
    }

    public function testKeepsRetryCountOfEventSentForRetry(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->sendEvent(self::FIRST_OPERATION_ID, new RedeliveryStamp(2));

        [$envelope] = $this->outbox->get();

        self::assertSame(2, RedeliveryStamp::getRetryCountFromEnvelope($envelope));
    }

    public function testDeliversEventOfUnknownTypeAsDecodingFailure(): void
    {
        self::mockTime('2026-10-24 12:00:00');

        $this->connection->insert(
            'outbox',
            [
                'type' => 'some.type',
                'body' => '{}',
                'available_at' => '2026-10-24 12:00:00+00',
            ],
        );

        $outboxMessageId = (int) $this->connection->lastInsertId();

        [$envelope] = $this->outbox->get();

        self::assertSame(
            [
                'message' => MessageDecodingFailedException::class,
                'encodedEnvelope' => ['body' => '{}', 'headers' => ['type' => 'some.type']],
                'outboxMessage' => $outboxMessageId,
            ],
            [
                'message' => $envelope->getMessage()::class,
                'encodedEnvelope' => $envelope->getMessage()->encodedEnvelope,
                'outboxMessage' => $envelope->last(OutboxMessageStamp::class)->id,
            ],
        );
    }

    private function sendEvent(string $operationId, StampInterface ...$stamps): void
    {
        $this->outbox->send(new Envelope(
            new OperationRejectedEvent(
                $operationId,
                RejectionReason::InsufficientFunds,
                new DateTimeImmutable('2026-10-24 12:00:00'),
            ),
            $stamps,
        ));
    }

    private function receiveOperationIds(): array
    {
        return array_map(
            static fn (Envelope $envelope): string => $envelope->getMessage()->operationId,
            [...$this->outbox->get()],
        );
    }

    private function getOperationIdsInOutbox(): array
    {
        return array_column(array_column($this->getOutboxEvents(), 'body'), 'operationId');
    }
}
