<?php

declare(strict_types=1);

namespace Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTestCase extends KernelTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
    }

    protected function tearDown(): void
    {
        unset($this->connection);

        parent::tearDown();
    }

    protected function addAccounts(array $accounts): void
    {
        foreach ($accounts as $name => [$balance, $version]) {
            $this->connection->insert(
                'accounts',
                [
                    'name' => $name,
                    'currency' => 'EUR',
                    'balance' => $balance,
                    'version' => $version,
                ],
            );
        }
    }

    protected function addHold(string $id, int $userId, string $amount, string $status, string $createdAt): void
    {
        $this->connection->insert(
            'ledger_transactions',
            [
                'id' => $id,
                'type' => 'hold',
                'currency' => 'EUR',
                'created_at' => $createdAt,
            ],
        );

        $this->connection->insert(
            'holds',
            [
                'id' => $id,
                'user_id' => $userId,
                'currency' => 'EUR',
                'amount' => $amount,
                'status' => $status,
            ],
        );
    }

    protected function getAccounts(): array
    {
        return array_map(
            static fn (array $account): array => [$account['balance'], $account['version']],
            $this->connection->fetchAllAssociativeIndexed('SELECT name, balance, version FROM accounts ORDER BY name'),
        );
    }

    protected function getLedgerTransactions(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, type, currency, created_at FROM ledger_transactions ORDER BY created_at, id',
        );
    }

    protected function getLedgerEntries(): array
    {
        return $this->connection->fetchAllKeyValue('SELECT account, amount FROM ledger_entries ORDER BY id');
    }

    protected function getHolds(): array
    {
        return array_map(
            static fn (array $hold): array => [$hold['amount'], $hold['status']],
            $this->connection->fetchAllAssociativeIndexed('SELECT id, amount, status FROM holds ORDER BY id'),
        );
    }

    protected function getOutboxEvents(): array
    {
        return array_map(
            static fn (array $event): array => [
                'type' => $event['type'],
                'body' => json_decode($event['body'], true),
            ],
            $this->connection->fetchAllAssociative('SELECT type, body FROM outbox ORDER BY id'),
        );
    }

    protected function getOutboxEventOutcomes(): array
    {
        return array_map(
            static fn (array $event): array => [
                'type' => $event['type'],
                ...array_intersect_key($event['body'], array_flip(['reason', 'balance', 'from', 'to'])),
            ],
            $this->getOutboxEvents(),
        );
    }

    protected function clearQueryLog(): void
    {
        self::getContainer()->get('doctrine.debug_data_holder')->reset();
    }

    protected function getLockedKeys(): array
    {
        $queries = self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'];

        return array_values(array_map(
            static fn (array $query): mixed => array_values($query['params'])[0],
            array_filter($queries, static fn (array $query): bool => str_ends_with($query['sql'], ' FOR UPDATE')),
        ));
    }
}
