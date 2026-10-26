<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Event\OperationEvent;
use App\Kafka\KafkaKeyStamp;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsMessageHandler(bus: 'event.bus', fromTransport: 'outbox')]
final readonly class PublishEventHandler
{
    public function __construct(
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(OperationEvent $event): void
    {
        try {
            $this->eventBus->dispatch(
                $event,
                [
                    new TransportNamesStamp('events'),
                    new KafkaKeyStamp($event->key()),
                ],
            );
        } catch (TransportException $exception) {
            throw new RecoverableMessageHandlingException($exception->getMessage(), previous: $exception);
        }
    }
}
