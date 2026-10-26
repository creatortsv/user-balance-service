<?php

declare(strict_types=1);

namespace Tests\Unit\MessageHandler;

use App\Enum\RejectionReason;
use App\Event\OperationRejectedEvent;
use App\MessageHandler\PublishEventHandler;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(PublishEventHandler::class)]
final class PublishEventHandlerTest extends TestCase
{
    public function testAsksToRetryWhenSendingFails(): void
    {
        $eventBus = $this->createStub(MessageBusInterface::class);

        $eventBus->method('dispatch')->willThrowException(new TransportException('Some error.'));

        $handler = new PublishEventHandler($eventBus);
        $event = new OperationRejectedEvent(
            'a0000000-0000-4000-8000-000000000001',
            RejectionReason::InsufficientFunds,
            new DateTimeImmutable('2026-10-24 12:00:00'),
        );

        $this->expectExceptionObject(new RecoverableMessageHandlingException('Some error.'));

        $handler($event);
    }
}
