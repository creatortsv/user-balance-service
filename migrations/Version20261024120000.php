<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ledger, holds, inbox and outbox';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE ledger_transactions (
            id UUID PRIMARY KEY,
            type VARCHAR(10) NOT NULL CHECK (type IN ('credit', 'debit', 'hold', 'capture', 'release', 'transfer')),
            currency CHAR(3) NOT NULL,
            created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
        )");

        $this->addSql('CREATE TABLE ledger_entries (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            transaction_id UUID NOT NULL REFERENCES ledger_transactions (id),
            account VARCHAR(64) NOT NULL,
            amount NUMERIC NOT NULL CHECK (amount <> 0)
        )');

        $this->addSql('CREATE INDEX ledger_entries_transaction_id_idx ON ledger_entries (transaction_id)');

        $this->addSql('CREATE INDEX ledger_entries_account_idx ON ledger_entries (account, id)');

        $this->addSql('CREATE TABLE accounts (
            name VARCHAR(64) NOT NULL,
            currency CHAR(3) NOT NULL,
            balance NUMERIC NOT NULL CHECK (balance >= 0),
            version BIGINT NOT NULL,
            PRIMARY KEY (name, currency)
        )');

        $this->addSql("CREATE TABLE holds (
            id UUID PRIMARY KEY REFERENCES ledger_transactions (id),
            user_id BIGINT NOT NULL,
            currency CHAR(3) NOT NULL,
            amount NUMERIC NOT NULL CHECK (amount > 0),
            status VARCHAR(10) NOT NULL CHECK (status IN ('active', 'captured', 'released'))
        )");

        $this->addSql('CREATE TABLE inbox (
            seq BIGINT GENERATED ALWAYS AS IDENTITY,
            message_id UUID PRIMARY KEY,
            type VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            ordering_key CHAR(64),
            retries INT NOT NULL DEFAULT 0,
            received_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            available_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            delivered_at TIMESTAMP(0) WITH TIME ZONE,
            processed_at TIMESTAMP(0) WITH TIME ZONE,
            failed_at TIMESTAMP(0) WITH TIME ZONE
        )');

        $this->addSql(
            'CREATE INDEX inbox_pending_idx ON inbox (seq) WHERE processed_at IS NULL AND failed_at IS NULL',
        );

        $this->addSql(
            'CREATE INDEX inbox_pending_key_idx ON inbox (ordering_key, seq)
            WHERE processed_at IS NULL AND failed_at IS NULL',
        );

        $this->addSql('CREATE TABLE outbox (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            type VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            retries INT NOT NULL DEFAULT 0,
            available_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            delivered_at TIMESTAMP(0) WITH TIME ZONE
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE outbox');
        $this->addSql('DROP TABLE inbox');
        $this->addSql('DROP TABLE holds');
        $this->addSql('DROP TABLE accounts');
        $this->addSql('DROP TABLE ledger_entries');
        $this->addSql('DROP TABLE ledger_transactions');
    }
}
