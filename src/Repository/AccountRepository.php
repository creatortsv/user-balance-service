<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountBalance;
use App\ValueObject\Account;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use LogicException;

final readonly class AccountRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function addIfMissing(AccountBalance $balance): void
    {
        $this->connection->executeStatement(
            'INSERT INTO accounts (name, currency, balance, version) VALUES (:name, :currency, :balance, :version)
            ON CONFLICT (name, currency) DO NOTHING',
            [
                'name' => $balance->account->name,
                'currency' => $balance->currency,
                'balance' => $balance->balance->value,
                'version' => $balance->version,
            ],
        );
    }

    public function find(Account $account, string $currency): ?AccountBalance
    {
        return $this->fetch(
            $this->select($account, $currency),
            $account,
            $currency,
        );
    }

    public function findForUpdate(Account $account, string $currency): ?AccountBalance
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('An account can be locked only inside a transaction.');
        }

        return $this->fetch(
            $this->select($account, $currency)->forUpdate(),
            $account,
            $currency,
        );
    }

    public function save(AccountBalance $balance): void
    {
        $this->connection->update(
            'accounts',
            [
                'balance' => $balance->balance->value,
                'version' => $balance->version,
            ],
            [
                'name' => $balance->account->name,
                'currency' => $balance->currency,
            ],
        );
    }

    private function select(Account $account, string $currency): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('balance', 'version')
            ->from('accounts')
            ->where('name = :name', 'currency = :currency')
            ->setParameters(
                [
                    'name' => $account->name,
                    'currency' => $currency,
                ],
            );
    }

    private function fetch(QueryBuilder $query, Account $account, string $currency): ?AccountBalance
    {
        $values = $query->fetchAssociative();

        if ($values === false) {
            return null;
        }

        $row = new Row($values);

        return new AccountBalance(
            $account,
            $row->number('balance'),
            $currency,
            $row->int('version'),
        );
    }
}
