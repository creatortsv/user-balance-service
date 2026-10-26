<?php

declare(strict_types=1);

namespace Tests\Integration\Middleware;

use App\Message\CreditMessage;
use App\Message\DebitMessage;
use App\Middleware\InboxMiddleware;
use App\Repository\InboxRepository;
use App\Repository\Row;
use BcMath\Number;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Tests\DatabaseTestCase;

#[CoversClass(InboxMiddleware::class)]
#[CoversClass(InboxRepository::class)]
#[CoversClass(Row::class)]
final class InboxMiddlewareTest extends DatabaseTestCase
{
    use ClockSensitiveTrait;

    private const string OPERATION_ID = 'a0000000-0000-4000-8000-000000000001';

    private InboxMiddleware $inboxMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inboxMiddleware = self::getContainer()->get(InboxMiddleware::class);
    }

    protected function tearDown(): void
    {
        unset($this->inboxMiddleware);

        parent::tearDown();
    }

    #[DataProvider('storesCommandOnceWithoutHandlingItDataProvider')]
    public function testStoresCommandOnceWithoutHandlingIt(string $firstAmount, string $secondAmount): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $handledOperations = [];
        $bus = $this->createBus(
            CreditMessage::class,
            static function (CreditMessage $message) use (&$handledOperations): void {
                $handledOperations[] = $message->operationId;
            },
        );

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number($firstAmount)),
            [new ReceivedStamp('commands')],
        );

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number($secondAmount)),
            [new ReceivedStamp('commands')],
        );

        self::assertSame(
            [
                'handledOperations' => [],
                'inbox' => [
                    self::OPERATION_ID => [
                        'type' => 'balance.credit',
                        'body' => '{"operationId":"a0000000-0000-4000-8000-000000000001","userId":1,'
                            . '"currency":"EUR","amount":"10.00"}',
                        'available_at' => '2026-10-26 12:00:00+00',
                    ],
                ],
            ],
            [
                'handledOperations' => $handledOperations,
                'inbox' => $this->connection->fetchAllAssociativeIndexed(
                    'SELECT message_id, type, body, available_at FROM inbox',
                ),
            ],
        );
    }

    public static function storesCommandOnceWithoutHandlingItDataProvider(): iterable
    {
        yield 'same command' => ['firstAmount' => '10.00', 'secondAmount' => '10.00'];

        yield 'same amount written differently' => ['firstAmount' => '10.00', 'secondAmount' => '10'];
    }

    public function testStoresCommandDispatchedWithoutTransport(): void
    {
        $handledOperations = [];
        $bus = $this->createBus(
            CreditMessage::class,
            static function (CreditMessage $message) use (&$handledOperations): void {
                $handledOperations[] = $message->operationId;
            },
        );

        $bus->dispatch(new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')));

        self::assertSame(
            ['handledOperations' => [], 'inbox' => [self::OPERATION_ID]],
            [
                'handledOperations' => $handledOperations,
                'inbox' => $this->connection->fetchFirstColumn('SELECT message_id FROM inbox'),
            ],
        );
    }

    public function testRefusesOperationIdReusedWithOtherData(): void
    {
        $bus = new MessageBus([$this->inboxMiddleware]);

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
            [new ReceivedStamp('commands')],
        );

        $this->expectExceptionObject(
            new UnrecoverableMessageHandlingException(
                'The operation "a0000000-0000-4000-8000-000000000001" was already received with other data.',
            ),
        );

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('20.00')),
            [new ReceivedStamp('commands')],
        );
    }

    public function testRefusesOperationIdReusedByAnotherCommand(): void
    {
        $bus = new MessageBus([$this->inboxMiddleware]);

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
            [new ReceivedStamp('commands')],
        );

        $this->expectExceptionObject(
            new UnrecoverableMessageHandlingException(
                'The operation "a0000000-0000-4000-8000-000000000001" was already received with other data.',
            ),
        );

        $bus->dispatch(
            new DebitMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
            [new ReceivedStamp('commands')],
        );
    }

    public function testHandlesStoredCommandOnce(): void
    {
        self::mockTime('2026-10-26 12:00:00');

        $handledOperations = [];
        $bus = $this->createBus(
            CreditMessage::class,
            static function (CreditMessage $message) use (&$handledOperations): void {
                $handledOperations[] = $message->operationId;
            },
        );

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
            [new ReceivedStamp('commands')],
        );

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
            [new ReceivedStamp('inbox')],
        );

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
            [new ReceivedStamp('inbox')],
        );

        self::assertSame(
            [
                'handledOperations' => [self::OPERATION_ID],
                'processedAt' => [self::OPERATION_ID => '2026-10-26 12:00:00+00'],
            ],
            [
                'handledOperations' => $handledOperations,
                'processedAt' => $this->connection->fetchAllKeyValue('SELECT message_id, processed_at FROM inbox'),
            ],
        );
    }

    public function testKeepsCommandPendingWhenHandlingFailed(): void
    {
        $bus = $this->createBus(
            CreditMessage::class,
            static function (): void {
                throw new RuntimeException('Some error.');
            },
        );

        $bus->dispatch(
            new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
            [new ReceivedStamp('commands')],
        );

        try {
            $bus->dispatch(
                new CreditMessage(self::OPERATION_ID, 1, 'EUR', new Number('10.00')),
                [new ReceivedStamp('inbox')],
            );
        } catch (HandlerFailedException $exception) {
        }

        self::assertSame(
            [
                'error' => 'Some error.',
                'processedAt' => [self::OPERATION_ID => null],
            ],
            [
                'error' => $exception->getPrevious()->getMessage(),
                'processedAt' => $this->connection->fetchAllKeyValue('SELECT message_id, processed_at FROM inbox'),
            ],
        );
    }

    public function testPassesOtherMessagesThrough(): void
    {
        $handledMessages = 0;
        $bus = $this->createBus(
            stdClass::class,
            static function () use (&$handledMessages): void {
                $handledMessages++;
            },
        );

        $bus->dispatch(new stdClass());

        self::assertSame(
            ['handledMessages' => 1, 'inbox' => []],
            [
                'handledMessages' => $handledMessages,
                'inbox' => $this->connection->fetchFirstColumn('SELECT message_id FROM inbox'),
            ],
        );
    }

    private function createBus(string $messageClass, Closure $handler): MessageBus
    {
        return new MessageBus(
            [
                $this->inboxMiddleware,
                new HandleMessageMiddleware(new HandlersLocator([$messageClass => [$handler]])),
            ],
        );
    }
}
