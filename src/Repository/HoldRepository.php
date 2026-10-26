<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Hold;
use App\Enum\HoldStatus;
use Doctrine\DBAL\Connection;
use LogicException;

final readonly class HoldRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function add(Hold $hold): void
    {
        $this->connection->insert(
            'holds',
            [
                'id' => $hold->id,
                'user_id' => $hold->userId,
                'currency' => $hold->currency,
                'amount' => $hold->amount->value,
                'status' => $hold->status->value,
            ],
        );
    }

    public function findForUpdate(string $id): ?Hold
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('A hold can be locked only inside a transaction.');
        }

        $values = $this->connection->createQueryBuilder()
            ->select('user_id', 'currency', 'amount', 'status')
            ->from('holds')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->forUpdate()
            ->fetchAssociative();

        if ($values === false) {
            return null;
        }

        $row = new Row($values);

        return new Hold(
            $id,
            $row->int('user_id'),
            $row->string('currency'),
            $row->number('amount'),
            HoldStatus::from($row->string('status')),
        );
    }

    public function save(Hold $hold): void
    {
        $this->connection->update(
            'holds',
            [
                'status' => $hold->status->value,
            ],
            [
                'id' => $hold->id,
            ],
        );
    }
}
