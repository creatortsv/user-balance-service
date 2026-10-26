<?php

declare(strict_types=1);

namespace Tests\Support\Containers;

use PDO;
use Symfony\Component\Process\Process;
use Testcontainers\Container\StartedGenericContainer;
use Testcontainers\Modules\PostgresContainer;

final class Containers
{
    private const string LIFETIME_SECONDS = '3600';

    private ?StartedGenericContainer $postgres = null;

    public function prepare(): void
    {
        $this->postgres ??= $this->startPostgres();

        $this->resetDatabase($this->postgres);
    }

    public function stop(): void
    {
        $this->postgres?->getClient()->containerStop($this->postgres->getId());

        $this->postgres = null;
    }

    private function startPostgres(): StartedGenericContainer
    {
        $container = new PostgresContainer('18-alpine', 'app', 'app', 'balance')
            ->withEntryPoint('timeout')
            ->withCommand([
                self::LIFETIME_SECONDS,
                'docker-entrypoint.sh',
                'postgres',
                '-c',
                'fsync=off',
                '-c',
                'synchronous_commit=off',
            ])
            ->withTmpfs('/var/lib/postgresql')
            ->withAutoRemove(true)
            ->start();

        $this->setEnvironment('DATABASE_URL', sprintf(
            'postgresql://app:app@%s:%d/balance?serverVersion=18&charset=utf8',
            $container->getHost(),
            $container->getMappedPort(5432),
        ));

        new Process(
            [PHP_BINARY, 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'],
            dirname(__DIR__, 3),
            ['APP_ENV' => 'test', 'SYMFONY_DOTENV_VARS' => false],
        )->mustRun();

        return $container;
    }

    private function resetDatabase(StartedGenericContainer $postgres): void
    {
        $database = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=balance', $postgres->getHost(), $postgres->getMappedPort(5432)),
            'app',
            'app',
        );

        $database->exec('TRUNCATE ledger_entries, holds, ledger_transactions, accounts, inbox, outbox');
    }

    private function setEnvironment(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv(sprintf('%s=%s', $name, $value));
    }
}
