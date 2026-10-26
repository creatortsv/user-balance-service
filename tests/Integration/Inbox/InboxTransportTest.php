<?php

declare(strict_types=1);

namespace Tests\Integration\Inbox;

use App\Inbox\InboxMessageStamp;
use App\Inbox\InboxTransport;
use App\Inbox\InboxTransportFactory;
use App\Message\CreditMessage;
use App\Repository\Row;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentForRetryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Tests\DatabaseTestCase;

#[CoversClass(InboxTransport::class)]
#[CoversClass(InboxTransportFactory::class)]
#[CoversClass(InboxMessageStamp::class)]
#[CoversClass(Row::class)]
final class InboxTransportTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string FIRST_ID = 'a0000000-0000-4000-8000-000000000001';
    private const string SECOND_ID = 'b0000000-0000-4000-8000-000000000002';
    private const string THIRD_ID = 'c0000000-0000-4000-8000-000000000003';

    private TransportInterface $inbox;
    private InMemoryTransport $failedTransport;
    private Connection $otherProcessorConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = self::getContainer()->get('messenger.transport.inbox');
        $this->failedTransport = self::getContainer()->get('messenger.transport.failed');
        $this->otherProcessorConnection = DriverManager::getConnection($this->connection->getParams());
    }

    protected function tearDown(): void
    {
        $this->otherProcessorConnection->close();

        unset($this->inbox, $this->failedTransport, $this->otherProcessorConnection);

        parent::tearDown();
    }

    public function testDeliversCommandsOneByOneInOrderOfReceipt(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', null, '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::SECOND_ID, 'balance.credit', null, '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::THIRD_ID, 'balance.credit', null, '2026-10-26 11:59:00+00');

        self::assertSame(
            [[self::FIRST_ID], [self::SECOND_ID], [self::THIRD_ID], []],
            [
                $this->receiveMessageIds(),
                $this->receiveMessageIds(),
                $this->receiveMessageIds(),
                $this->receiveMessageIds(),
            ],
        );
    }

    public function testDeliversOnlyFirstPendingCommandOfEachKey(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::SECOND_ID, 'balance.credit', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::THIRD_ID, 'balance.credit', hash('sha256', '2'), '2026-10-26 11:59:00+00');

        self::assertSame(
            [[self::FIRST_ID], [self::THIRD_ID], []],
            [$this->receiveMessageIds(), $this->receiveMessageIds(), $this->receiveMessageIds()],
        );
    }

    #[DataProvider('deliversNextCommandOfKeyOnceFirstIsFinishedDataProvider')]
    public function testDeliversNextCommandOfKeyOnceFirstIsFinished(
        array $firstState,
        array $expectedMessageIds,
    ): void {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::SECOND_ID, 'balance.credit', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $this->connection->update('inbox', $firstState, ['message_id' => self::FIRST_ID]);

        self::assertSame($expectedMessageIds, $this->receiveMessageIds());
    }

    public static function deliversNextCommandOfKeyOnceFirstIsFinishedDataProvider(): iterable
    {
        yield 'first taken' => [
            'firstState' => ['delivered_at' => '2026-10-26 12:00:00+00'],
            'expectedMessageIds' => [],
        ];

        yield 'first waiting for retry' => [
            'firstState' => ['available_at' => '2026-10-26 12:00:01+00'],
            'expectedMessageIds' => [],
        ];

        yield 'first processed' => [
            'firstState' => ['delivered_at' => '2026-10-26 12:00:00+00', 'processed_at' => '2026-10-26 12:00:00+00'],
            'expectedMessageIds' => [self::SECOND_ID],
        ];

        yield 'first failed' => [
            'firstState' => ['delivered_at' => '2026-10-26 12:00:00+00', 'failed_at' => '2026-10-26 12:00:00+00'],
            'expectedMessageIds' => [self::SECOND_ID],
        ];
    }

    public function testRedeliversCommandTakenByStoppedProcessor(): void
    {
        $clock = self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', null, '2026-10-26 11:59:00+00');

        $receivedFirst = $this->receiveMessageIds();

        $clock->sleep(59);

        $receivedWhileTaken = $this->receiveMessageIds();

        $clock->sleep(1);

        $receivedAfterTimeout = $this->receiveMessageIds();

        self::assertSame(
            ['first' => [self::FIRST_ID], 'whileTaken' => [], 'afterTimeout' => [self::FIRST_ID]],
            ['first' => $receivedFirst, 'whileTaken' => $receivedWhileTaken, 'afterTimeout' => $receivedAfterTimeout],
        );
    }

    public function testSkipsCommandLockedByAnotherProcessor(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::SECOND_ID, 'balance.credit', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::THIRD_ID, 'balance.credit', hash('sha256', '2'), '2026-10-26 11:59:00+00');

        $this->otherProcessorConnection->beginTransaction();

        $this->otherProcessorConnection->executeQuery(
            'SELECT message_id FROM inbox WHERE message_id = :messageId FOR UPDATE',
            ['messageId' => self::FIRST_ID],
        );

        $this->connection->executeStatement("SET lock_timeout = '1s'");

        self::assertSame(
            [[self::THIRD_ID], []],
            [$this->receiveMessageIds(), $this->receiveMessageIds()],
        );
    }

    #[DataProvider('deliversStoredCommandDataProvider')]
    public function testDeliversStoredCommand(string $type, string $expectedMessageClass): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, $type, null, '2026-10-26 11:59:00+00');

        $this->connection->update('inbox', ['retries' => 2], ['message_id' => self::FIRST_ID]);

        [$envelope] = $this->inbox->get();

        self::assertSame(
            ['message' => $expectedMessageClass, 'messageId' => self::FIRST_ID, 'retries' => 2],
            [
                'message' => $envelope->getMessage()::class,
                'messageId' => $envelope->last(InboxMessageStamp::class)->messageId,
                'retries' => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
            ],
        );
    }

    public static function deliversStoredCommandDataProvider(): iterable
    {
        yield 'known type' => [
            'type' => 'balance.credit',
            'expectedMessageClass' => CreditMessage::class,
        ];

        yield 'unknown type' => [
            'type' => 'balance.some',
            'expectedMessageClass' => MessageDecodingFailedException::class,
        ];
    }

    public function testReschedulesCommandSentForRetry(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', null, '2026-10-26 11:59:00+00');

        [$envelope] = $this->inbox->get();

        $this->inbox->send($envelope->with(new DelayStamp(3000), new RedeliveryStamp(1)));

        $this->inbox->reject($envelope->with(new SentForRetryStamp(true)));

        self::assertSame(
            [
                self::FIRST_ID => [
                    'retries' => 1,
                    'available_at' => '2026-10-26 12:00:03+00',
                    'delivered_at' => null,
                    'processed_at' => null,
                    'failed_at' => null,
                ],
            ],
            $this->getInboxMessages(),
        );
    }

    public function testMarksCommandFailedWhenNotRetried(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', null, '2026-10-26 11:59:00+00');

        [$envelope] = $this->inbox->get();

        $this->inbox->reject($envelope->with(new SentForRetryStamp(false)));

        self::assertSame(
            [
                self::FIRST_ID => [
                    'retries' => 0,
                    'available_at' => '2026-10-26 11:59:00+00',
                    'delivered_at' => '2026-10-26 12:00:00+00',
                    'processed_at' => null,
                    'failed_at' => '2026-10-26 12:00:00+00',
                ],
            ],
            $this->getInboxMessages(),
        );
    }

    public function testLeavesCommandAsIsOnAck(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.credit', null, '2026-10-26 11:59:00+00');

        [$envelope] = $this->inbox->get();

        $this->inbox->ack($envelope);

        self::assertSame(
            [
                self::FIRST_ID => [
                    'retries' => 0,
                    'available_at' => '2026-10-26 11:59:00+00',
                    'delivered_at' => '2026-10-26 12:00:00+00',
                    'processed_at' => null,
                    'failed_at' => null,
                ],
            ],
            $this->getInboxMessages(),
        );
    }

    public function testRetriesFailingCommandWhileNextOfItsKeyWaits(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $this->addInboxMessage(self::FIRST_ID, 'balance.some', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $this->addInboxMessage(self::SECOND_ID, 'balance.credit', hash('sha256', '1'), '2026-10-26 11:59:00+00');

        $processor = new CommandTester(new Application(self::$kernel)->find('messenger:consume'));

        $processor->execute(
            [
                'receivers' => ['inbox'],
                '--bus' => 'command.bus',
                '--limit' => 5,
                '--time-limit' => 60,
                '--no-reset' => null,
            ],
        );

        self::assertSame(
            [
                'inbox' => [
                    self::FIRST_ID => [
                        'retries' => 3,
                        'available_at' => '2026-10-26 12:00:13+00',
                        'delivered_at' => '2026-10-26 12:00:13+00',
                        'processed_at' => null,
                        'failed_at' => '2026-10-26 12:00:13+00',
                    ],
                    self::SECOND_ID => [
                        'retries' => 0,
                        'available_at' => '2026-10-26 11:59:00+00',
                        'delivered_at' => '2026-10-26 12:00:13+00',
                        'processed_at' => '2026-10-26 12:00:13+00',
                        'failed_at' => null,
                    ],
                ],
                'failed' => [MessageDecodingFailedException::class],
                'events' => ['balance.credited'],
            ],
            [
                'inbox' => $this->getInboxMessages(),
                'failed' => array_map(
                    static fn (Envelope $envelope): string => $envelope->getMessage()::class,
                    $this->failedTransport->getSent(),
                ),
                'events' => array_column($this->getOutboxEvents(), 'type'),
            ],
        );
    }

    private function addInboxMessage(string $messageId, string $type, ?string $orderingKey, string $receivedAt): void
    {
        $this->connection->insert(
            'inbox',
            [
                'message_id' => $messageId,
                'type' => $type,
                'body' => sprintf('{"operationId":"%s","userId":1,"currency":"EUR","amount":"10.00"}', $messageId),
                'ordering_key' => $orderingKey,
                'received_at' => $receivedAt,
                'available_at' => $receivedAt,
            ],
        );
    }

    private function receiveMessageIds(): array
    {
        return array_map(
            static fn (Envelope $envelope): string => $envelope->last(InboxMessageStamp::class)->messageId,
            [...$this->inbox->get()],
        );
    }

    private function getInboxMessages(): array
    {
        return $this->connection->fetchAllAssociativeIndexed(
            'SELECT message_id, retries, available_at, delivered_at, processed_at, failed_at FROM inbox ORDER BY seq',
        );
    }
}
