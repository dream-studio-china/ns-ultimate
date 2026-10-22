# Admin agent conflict feedback

## Scope

Distinguish a definitive stale-snapshot rejection from an uncertain submission, without expanding mutation capabilities or changing core/business code.

## Changes

- Added an app-owned conflict exception for adapter snapshot/target checks before domain mutation. Commit and restore expose a bounded `proposal_conflict` marker only after transaction rollback.
- Added a non-submittable local `conflicted` card state, localized feedback, and explicit discard. Confirm remains disabled; chat and clearing are no longer blocked by a definitive conflict.
- Kept unclassified HTTP errors, timeouts and aborted requests uncertain. A pending outcome now requires original-preview revalidation before re-enabling explicit confirmation; no write is retried automatically.
- Kept stored operation statuses and schema unchanged. A conflicted operation remains pending until cancellation or expiry; discard erases its proposal values through the existing cancellation endpoint.

## Validation

- Backend suite: 186 tests, 4,212 assertions passed.
- Frontend/script suite: 127 tests passed, including preview identity/expiry validation during pending-outcome recovery.
- PHPStan, composed production build and `git diff --check` passed.
- Real Symfony HTTP backend and composed frontend, using the existing isolated SQLite fixture and deterministic provider responses: concurrent update caused a conflict message, disabled confirm, enabled discard/chat, and no outcome button. Discard returned a cancelled receipt; the concurrent title remained intact.
- Deliberately losing the conflict response first blocked chat as unknown. Outcome lookup then revalidated the original preview, discovered the conflict, and unlocked chat without re-enabling confirmation. Only one commit request was issued.
- Existing Qiniu deprecation and build warnings remain. No deployment database or migration was touched; production-engine concurrency remains outside this verification.
