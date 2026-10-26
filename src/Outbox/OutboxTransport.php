<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Repository\Row;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final readonly class OutboxTransport implements TransportInterface
{
    private const int REDELIVER_TIMEOUT_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private SerializerInterface $serializer,
        private ClockInterface $clock,
    ) {
    }

    public function get(int $fetchSize = 1): iterable
    {
        $now = $this->clock->now();

        // TODO: relay берет события по одному и на каждое делает свой flush в Kafka, при большом потоке нужны пачки
        $values = $this->connection->fetchAssociative(
            'UPDATE outbox SET delivered_at = :now
            WHERE id = (
                SELECT id FROM outbox
                WHERE available_at <= :now AND (delivered_at IS NULL OR delivered_at <= :redeliverBefore)
                ORDER BY id
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id, type, body, retries',
            [
                'now' => $now,
                'redeliverBefore' => $now->modify(sprintf('-%d seconds', self::REDELIVER_TIMEOUT_SECONDS)),
            ],
            [
                'now' => Types::DATETIMETZ_IMMUTABLE,
                'redeliverBefore' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        if ($values === false) {
            return [];
        }

        $row = new Row($values);

        return [
            $this->serializer
                ->decode(
                    [
                        'body' => $row->string('body'),
                        'headers' => ['type' => $row->string('type')],
                    ],
                )
                ->with(
                    new OutboxMessageStamp($row->int('id')),
                    new RedeliveryStamp($row->int('retries')),
                ),
        ];
    }

    public function ack(Envelope $envelope): void
    {
        $this->delete($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->delete($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        $encoded = $this->serializer->encode($envelope);
        $delay = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;

        $this->connection->insert(
            'outbox',
            [
                'type' => $encoded['headers']['type'] ?? $envelope->getMessage()::class,
                'body' => $encoded['body'],
                'retries' => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
                'available_at' => $this->clock->now()->modify(sprintf('+%d milliseconds', $delay)),
            ],
            [
                'retries' => ParameterType::INTEGER,
                'available_at' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return $envelope;
    }

    private function delete(Envelope $envelope): void
    {
        $stamp = $envelope->last(OutboxMessageStamp::class) ?? throw new LogicException('It is not an outbox message.');

        $this->connection->delete('outbox', ['id' => $stamp->id]);
    }
}
