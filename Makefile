DC := docker compose

# Extra arguments passed through to bin/console, e.g.
#   make import ARGS="--limit=100000"
ARGS ?=

.DEFAULT_GOAL := help

.PHONY: help build up down destroy install import import-mysql import-es \
        import-addresses benchmark health logs shell mysql-cli reset

help: ## Show this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "\033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

build: ## Build the php image
	$(DC) build

up: build ## Start the stack (installs deps if vendor/ is missing) and wait until healthy
	$(DC) up -d --wait
	@if [ ! -d vendor ]; then echo ">> vendor/ missing, running composer install"; $(MAKE) install; fi
	@echo ""
	@echo ">> Stack is up: http://localhost:8080"

down: ## Stop the stack, keeping the data volumes
	$(DC) down

destroy: ## Stop the stack AND delete the volumes -- this wipes all imported data
	$(DC) down -v

install: ## composer install inside the php container
	$(DC) exec php composer install

import: ## Import into both engines (ARGS="--limit=100000" to shorten)
	$(DC) exec php php bin/console import --engine=all --recreate $(ARGS)

import-mysql: ## Import into MySQL only
	$(DC) exec php php bin/console import --engine=mysql --recreate $(ARGS)

import-es: ## Import into Elasticsearch only
	$(DC) exec php php bin/console import --engine=elasticsearch --recreate $(ARGS)

import-addresses: ## Import down to house-number level (4.2M docs, takes a while)
	$(DC) exec php php bin/console import --engine=all --level=all --recreate $(ARGS)

benchmark: ## Run the MySQL vs Elasticsearch benchmark (ARGS passthrough)
	$(DC) exec php php bin/console benchmark $(ARGS)

health: ## Check that MySQL and Elasticsearch are reachable and populated
	$(DC) exec php php bin/console health

logs: ## Follow the logs of all services
	$(DC) logs -f

shell: ## Open a bash shell in the php container
	$(DC) exec php bash

mysql-cli: ## Open a mysql client on the autocomplete database
	$(DC) exec mysql mysql -uautocomplete -pautocomplete autocomplete

reset: ## Clean rebuild: destroy volumes, start fresh, install and import
	$(MAKE) destroy
	$(MAKE) up
	$(MAKE) install
	$(MAKE) import
