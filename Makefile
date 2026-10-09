.DEFAULT_GOAL := help
SHELL := /bin/bash
ROOT := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
ARGS ?=
HOST ?= 127.0.0.1
ADMIN_PORT ?= 9528
BACKEND_PORT ?= 8000
export HOST ADMIN_PORT BACKEND_PORT

.PHONY: help install env-init env-check dev admin backend backend-debug build preview test test-workflow test-admin test-backend type-check lint console routes container cache-clear logs migrate-status migrate

help:
	@printf '%s\n' \
	  'make install        Install dependencies without Composer auto-scripts' \
	  'make env-init       Create local env files and development JWT keys (no overwrite)' \
	  'make env-check      Check local configuration without printing values' \
	  'make dev            Start both apps; Ctrl-C stops both' \
	  'make admin/backend  Start one app (HOST, ADMIN_PORT, BACKEND_PORT)' \
	  'make backend-debug  Start backend with Xdebug (extension required)' \
	  'make build/preview  Build or preview the composed admin app' \
	  'make test           Run admin core and frontend/backend composition tests' \
	  'make type-check/lint  Run core admin checks' \
	  'make console ARGS="about"  Run the integration console' \
	  'make routes/container/cache-clear/logs  Backend debugging' \
	  'make migrate-status/migrate  Inspect or explicitly apply migrations' \
	  'Pass extra CLI options with ARGS="..."; use APP_ENV for backend environments.'

install:
	@cd "$(ROOT)" && npm run deps:install

env-init env-check dev admin backend backend-debug build preview test test-workflow test-admin test-backend type-check lint console routes container cache-clear logs migrate-status migrate:
	@bash "$(ROOT)scripts/project.sh" "$@" $(ARGS)
