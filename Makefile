export HOST_PROJECT_DIR := $(CURDIR)

DC  := docker compose
BIN := docker/bin

.DEFAULT_GOAL := help
.PHONY: help init up down test cs stan qa send events balances ledger failed failed-retry

help: ## Show available commands
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-14s\033[0m %s\n", $$1, $$2}'

init: ## First run: build images, install dependencies, start the stack
	$(DC) build
	$(BIN)/composer install
	$(DC) up -d --wait

up: ## Start the stack (Kafka UI on :8080)
	$(DC) up -d --wait

down: ## Stop the stack
	$(DC) down

test: ## Run tests: make test [s=unit|integration] [f=FilterPattern]
	$(BIN)/phpunit $(if $(s),--testsuite '$(s)') $(if $(f),--filter '$(f)')

cs: ## Code style (PSR-12)
	$(BIN)/phpcs

stan: ## PHPStan
	$(BIN)/console cache:warmup
	$(BIN)/phpstan analyse

qa: cs stan test ## Code style, PHPStan and tests

send: ## Publish a command: make send t=balance.credit k=1 m='{"operationId":"...","userId":1,"currency":"EUR","amount":"100.00"}'
	printf 'type:%s\t%s\t%s\n' '$(t)' '$(k)' '$(m)' | $(DC) exec -T kafka /opt/kafka/bin/kafka-console-producer.sh \
		--bootstrap-server localhost:9092 --topic balance.commands \
		--reader-property parse.key=true --reader-property parse.headers=true

events: ## Show all published events
	$(DC) exec -T kafka /opt/kafka/bin/kafka-console-consumer.sh --bootstrap-server localhost:9092 \
		--topic balance.events --from-beginning --timeout-ms 5000 \
		--formatter-property print.key=true --formatter-property print.headers=true 2>/dev/null

balances: ## Show the stored balance of every account
	$(DC) exec postgres psql -U app -d balance -c 'SELECT name, currency, balance FROM accounts ORDER BY name'

ledger: ## Show all ledger entries, transaction by transaction
	$(DC) exec postgres psql -U app -d balance -c 'SELECT t.created_at, t.type, t.id AS transaction, e.account, e.amount \
		FROM ledger_entries e JOIN ledger_transactions t ON t.id = e.transaction_id ORDER BY e.id'

failed: ## Show commands in the dead-letter queue
	$(BIN)/console messenger:failed:show -vv

failed-retry: ## Process the dead-letter queue again, asking what to do with each command
	$(BIN)/console messenger:failed:retry -vv
