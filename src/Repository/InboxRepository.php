<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class InboxRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function add(
        string $messageId,
        string $type,
        string $body,
        ?string $orderingKey,
        DateTimeImmutable $receivedAt,
    ): bool {
        $added = $this->connection->executeStatement(
            'INSERT INTO inbox (message_id, type, body, ordering_key, received_at, available_at)
            VALUES (:messageId, :type, :body, :orderingKey, :receivedAt, :receivedAt)
            ON CONFLICT (message_id) DO NOTHING',
            [
                'messageId' => $messageId,
                'type' => $type,
                'body' => $body,
                'orderingKey' => $orderingKey === null ? null : hash('sha256', $orderingKey),
                'receivedAt' => $receivedAt,
            ],
            [
                'receivedAt' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return $added === 1;
    }

    public function contains(string $messageId, string $type, string $body): bool
    {
        $row = new Row(
            $this->connection->fetchAssociative(
                'SELECT EXISTS (
                    SELECT 1 FROM inbox WHERE message_id = :messageId AND type = :type AND body = :body
                ) AS found',
                [
                    'messageId' => $messageId,
                    'type' => $type,
                    'body' => $body,
                ],
            ),
        );

        return $row->bool('found');
    }

    public function markProcessed(string $messageId, DateTimeImmutable $processedAt): bool
    {
        $marked = $this->connection->executeStatement(
            'UPDATE inbox SET processed_at = :processedAt WHERE message_id = :messageId AND processed_at IS NULL',
            [
                'messageId' => $messageId,
                'processedAt' => $processedAt,
            ],
            [
                'processedAt' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        return $marked === 1;
    }
}
