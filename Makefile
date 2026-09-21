DC := docker compose

# Extra arguments passed through to bin/console, e.g.
#   make import ARGS="--limit=100000"
ARGS ?=

# Path to the address CSV, forwarded as --csv. Required by the import targets --
# there is no default filename. Paths resolve inside the container, where the
# project root is mounted at /app:
#   make import CSV=openaddress-bevlg.csv
#   make import CSV=data/brussels.csv
CSV ?=
CSV_ARG := $(if $(CSV),--csv=$(CSV),)

# Fail with advice rather than letting bin/console report a missing path, since
# by then you have already waited for docker compose exec to start php.
require-csv:
	@test -n "$(CSV)" || { \
		echo "CSV is not set. Name the file to import, e.g."; \
		echo "  make $(MAKECMDGOALS) CSV=openaddress-bevlg.csv"; \
		echo "Paths are relative to the project root (mounted at /app)."; \
		exit 1; \
	}

.DEFAULT_GOAL := help

.PHONY: help build up down destroy install require-csv import import-mysql \
        import-es import-addresses benchmark health logs shell mysql-cli reset

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

import: require-csv ## Import into both engines (needs CSV=path; ARGS="--limit=100000" to shorten)
	$(DC) exec php php bin/console import --engine=all --recreate $(CSV_ARG) $(ARGS)

import-mysql: require-csv ## Import into MySQL only (needs CSV=path)
	$(DC) exec php php bin/console import --engine=mysql --recreate $(CSV_ARG) $(ARGS)

import-es: require-·csv ## Import into Elasticsearch only (needs CSV=path)
	$(DC) exec php php bin/console import --engine=elasticsearch --recreate $(CSV_ARG) $(ARGS)

import-addresses: require-csv ## Import down to house-number level, 4.2M docs (needs CSV=path)
	$(DC) exec php php bin/console import --engine=all --level=all --recreate $(CSV_ARG) $(ARGS)

benchmark: ## Run the MySQL vs Elasticsearch benchmark (ARGS passthrough)
	$(DC) exec php php bin/console benchmark $(ARGS)

health: ## Check that MySQL and Elasticsearch are reachable and populated
	$(DC) exec php php bin/console health $(CSV_ARG)

logs: ## Follow the logs of all services
	$(DC) logs -f

shell: ## Open a bash shell in the php container
	$(DC) exec php bash

mysql-cli: ## Open a mysql client on the autocomplete database
	$(DC) exec mysql mysql -uautocomplete -pautocomplete autocomplete

reset: require-csv ## Clean rebuild: destroy volumes, start fresh, install and import (needs CSV=path)
	$(MAKE) destroy
	$(MAKE) up
	$(MAKE) install
	$(MAKE) import CSV="$(CSV)" ARGS="$(ARGS)"
