<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LedgerTransaction;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class LedgerTransactionRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function add(LedgerTransaction $transaction, DateTimeImmutable $createdAt): void
    {
        $this->connection->insert(
            'ledger_transactions',
            [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'currency' => $transaction->currency,
                'created_at' => $createdAt,
            ],
            [
                'created_at' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );

        foreach ($transaction->entries() as $entry) {
            $this->connection->insert(
                'ledger_entries',
                [
                    'transaction_id' => $transaction->id,
                    'account' => $entry->account->name,
                    'amount' => $entry->amount->value,
                ],
            );
        }
    }
}
