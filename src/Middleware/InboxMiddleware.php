<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Kafka\KafkaMessageStamp;
use App\Message\OperationMessage;
use App\Repository\InboxRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class InboxMiddleware implements MiddlewareInterface
{
    private const string INBOX_TRANSPORT = 'inbox';

    public function __construct(
        private Connection $connection,
        private InboxRepository $inbox,
        #[Autowire(service: 'messenger.transport.symfony_serializer')]
        private SerializerInterface $serializer,
        private ClockInterface $clock,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof OperationMessage) {
            return $stack->next()->handle($envelope, $stack);
        }

        if ($envelope->last(ReceivedStamp::class)?->getTransportName() !== self::INBOX_TRANSPORT) {
            $this->receive($message, $envelope->last(KafkaMessageStamp::class)?->message->key);

            return $envelope;
        }

        return $this->connection->transactional(
            fn (): Envelope => $this->inbox->markProcessed($message->operationId, $this->clock->now())
                ? $stack->next()->handle($envelope, $stack)
                : $envelope,
        );
    }

    private function receive(OperationMessage $message, ?string $orderingKey): void
    {
        // TODO: обработанные строки inbox копятся, нужна чистка по расписанию тех, что старше хранения в Kafka
        $encoded = $this->serializer->encode(new Envelope($message));
        $type = $encoded['headers']['type'] ?? $message::class;

        $added = $this->inbox->add(
            $message->operationId,
            $type,
            $encoded['body'],
            $orderingKey,
            $this->clock->now(),
        );

        if (!$added && !$this->inbox->contains($message->operationId, $type, $encoded['body'])) {
            throw new UnrecoverableMessageHandlingException(
                sprintf(
                    'The operation "%s" was already received with other data.',
                    $message->operationId,
                ),
            );
        }
    }
}
