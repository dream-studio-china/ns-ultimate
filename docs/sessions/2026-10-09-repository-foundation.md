# Repository foundation

## Goal

Establish the layered repository structure and import the upstream admin and backend cores.

## Completed

- Documented `core/`, `integration/`, and `business/` ownership boundaries.
- Added `immane/crud-admin` (`master`, `d8ce99b`) as a subtree at `core/crud-admin/`.
- Added `immane/crud-skeleton` (`main`, `10a489d`) as a subtree at `core/crud-skeleton/`.
- Configured local remotes `crud-admin` and `crud-skeleton`.
- Documented subtree update and upstream PR workflows in [`../operations/core-sync.md`](../operations/core-sync.md).

## Risks / next steps

- Review `core/crud-skeleton/.env.dev` and `.env.test` before publishing; the upstream files contain non-empty secret-like settings. Confirm they are safe development/test values and rotate any real credentials.
- Implement and validate frontend and Symfony registration seams before adding business modules. Record stable interfaces in `docs/contracts/`.
- No application build or integration tests have been run; only repository structure and subtree setup were established.
