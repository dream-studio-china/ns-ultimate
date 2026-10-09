.DEFAULT_GOAL := help
SHELL := /bin/bash
ROOT := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
ARGS ?=
HOST ?= 127.0.0.1
ADMIN_PORT ?= 9528
BACKEND_PORT ?= 8000
export HOST ADMIN_PORT BACKEND_PORT

.PHONY: help install env-init env-check dev-reset dev admin backend backend-debug build preview test test-workflow test-admin test-backend type-check lint console routes container cache-clear logs migrate-status migrate docker-dev docker-dev-reset docker-dev-up docker-dev-down docker-dev-status docker-dev-logs docker-console docker-migrate docker-migrate-status docker-test-backend

help:
	@printf '%s\n' 'Daily use' \
	  '---------' \
	  && printf '%-32s\t%s\n' \
	  'make install' 'Install dependencies without Composer auto-scripts' \
	  'make dev' 'First run sets up local MySQL; later runs start both apps' \
	  'make docker-dev' 'First run sets up Docker MySQL; later runs start the app' \
	  'make test' 'Run project workflow, admin, and backend tests' \
	  'make build' 'Build the composed admin app' \
	  'make preview' 'Preview the production admin build' \
	  'make type-check' 'Run admin type checks' \
	  'make lint' 'Run admin lint checks' \
	  && printf '\n%s\n%s\n' \
	  'Advanced / optional local commands' \
	  '---------------------------------' \
	  && printf '%-32s\t%s\n' \
	  'make dev-reset' 'Verify ownership and type exact DB target to reset' \
	  'make env-init' 'Create local env files and development JWT keys (no overwrite)' \
	  'make env-check' 'Check local configuration without printing values' \
	  'make admin' 'Start only the admin app (HOST, ADMIN_PORT)' \
	  'make backend' 'Start only the backend (HOST, BACKEND_PORT)' \
	  'make backend-debug' 'Start backend with Xdebug (extension required)' \
	  'make test-workflow' 'Run project workflow tests' \
	  'make test-admin' 'Run admin composition and core frontend tests' \
	  'make test-backend' 'Run backend integration tests' \
	  'make console ARGS="about"' 'Run an integration console command' \
	  'make routes ARGS="..."' 'Inspect backend routes' \
	  'make container ARGS="..."' 'Inspect the Symfony service container' \
	  'make cache-clear' 'Clear the backend cache' \
	  'make logs' 'Follow backend logs' \
	  'make migrate-status' 'Inspect pending backend migrations' \
	  'make migrate' 'Apply backend migrations' \
	  && printf '\n%s\n%s\n' \
	  'Advanced / optional Docker commands' \
	  '-----------------------------------' \
	  && printf '%-32s\t%s\n' \
	  'make docker-dev-reset' 'Verify local context/resources; confirm before reset' \
	  'make docker-dev-up' 'Auto-setup if needed; start DB/backend without Vite' \
	  'make docker-dev-down' 'Stop Docker development services; preserve volumes' \
	  'make docker-dev-status' 'Show Docker development service status' \
	  'make docker-dev-logs ARGS="php"' 'Follow Docker service logs' \
	  'make docker-console ARGS="about"' 'Run a console command in Docker PHP' \
	  'make docker-migrate-status' 'Inspect Docker DB migrations' \
	  'make docker-migrate' 'Apply Docker DB migrations' \
	  'make docker-test-backend' 'Run backend tests in Docker PHP' \
	  && printf '\n%s\n' \
	  'Extra CLI options can be passed with ARGS="...".'

install:
	@cd "$(ROOT)" && npm run deps:install

env-init env-check dev-reset dev admin backend backend-debug build preview test test-workflow test-admin test-backend type-check lint console routes container cache-clear logs migrate-status migrate:
	@bash "$(ROOT)scripts/project.sh" "$@" $(ARGS)

docker-dev docker-dev-reset docker-dev-up docker-dev-down docker-dev-status docker-dev-logs docker-console docker-migrate docker-migrate-status docker-test-backend:
	@bash "$(ROOT)scripts/dev-docker.sh" "$(if $(filter docker-dev-%,$@),$(patsubst docker-dev-%,%,$@),$(patsubst docker-%,%,$@))" $(ARGS)
