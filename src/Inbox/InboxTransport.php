<?php

declare(strict_types=1);

namespace App\Inbox;

use App\Repository\Row;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentForRetryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

final readonly class InboxTransport implements TransportInterface
{
    private const int REDELIVER_TIMEOUT_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private SerializerInterface $serializer,
        private ClockInterface $clock,
    ) {
    }

    public function get(): iterable
    {
        $now = $this->clock->now();

        // TODO: processor берет команды по одной и на каждую делает свой запрос, при большом потоке можно брать пачкой
        $values = $this->connection->fetchAssociative(
            'UPDATE inbox SET delivered_at = :now
            WHERE message_id = (
                SELECT candidate.message_id FROM inbox AS candidate
                WHERE candidate.processed_at IS NULL
                    AND candidate.failed_at IS NULL
                    AND candidate.available_at <= :now
                    AND (candidate.delivered_at IS NULL OR candidate.delivered_at <= :redeliverBefore)
                    AND NOT EXISTS (
                        SELECT 1 FROM inbox AS earlier
                        WHERE earlier.ordering_key = candidate.ordering_key
                            AND earlier.seq < candidate.seq
                            AND earlier.processed_at IS NULL
                            AND earlier.failed_at IS NULL
                    )
                ORDER BY candidate.seq
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            RETURNING message_id, type, body, retries',
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
                    new InboxMessageStamp($row->string('message_id')),
                    new RedeliveryStamp($row->int('retries')),
                ),
        ];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
        $stamp = $this->stamp($envelope);

        if ($envelope->last(SentForRetryStamp::class)?->isSent === true) {
            return;
        }

        $this->connection->update(
            'inbox',
            [
                'failed_at' => $this->clock->now(),
            ],
            [
                'message_id' => $stamp->messageId,
            ],
            [
                'failed_at' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );
    }

    public function send(Envelope $envelope): Envelope
    {
        $stamp = $this->stamp($envelope);
        $delay = $envelope->last(DelayStamp::class)?->getDelay() ?? 0;

        $this->connection->update(
            'inbox',
            [
                'retries' => RedeliveryStamp::getRetryCountFromEnvelope($envelope),
                'available_at' => $this->clock->now()->modify(sprintf('+%d milliseconds', $delay)),
                'delivered_at' => null,
            ],
            [
                'message_id' => $stamp->messageId,
            ],
            [
                'retries' => ParameterType::INTEGER,
                'available_at' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return $envelope;
    }

    private function stamp(Envelope $envelope): InboxMessageStamp
    {
        return $envelope->last(InboxMessageStamp::class) ?? throw new LogicException('It is not an inbox message.');
    }
}
