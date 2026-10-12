# WeChat Operations Runbook

Operational procedures for the store-scoped WeChat module (`business/backend/Wechat/`).
Background design and acceptance criteria live in
[`../tasks/store-wechat-configuration.md`](../tasks/store-wechat-configuration.md);
the validated interface contract is in [`../contracts/wechat-module.md`](../contracts/wechat-module.md).

All commands run through the integration console:

```sh
php integration/backend/bin/console <command>
```

No deployment, credential import, live charge, or refund is authorized by this
runbook alone. Production operations require explicit approval and a named operator.

## 1. Deployment

Deploy code with the standard release flow (see `release-and-rollback.md`).
Configuration-only publication never requires restart, Composer installation, or
cache clearing: the resolver reads authoritative database state on every call.

After deploying code that adds WeChat migrations:

1. Back up the production database.
2. Check migration status: `doctrine:migrations:status`.
3. Apply: `doctrine:migrations:migrate --no-interaction`.
4. Seed or re-run authorization (idempotent): `app:wechat:seed-authorization`.
5. Verify routes: `debug:router | grep -E 'wechat|webhooks'`.
6. Verify exactly one `wechat` gateway: `debug:container --tag=payment.gateway`.
7. Verify the container compiles: `APP_ENV=prod APP_DEBUG=0 bin/console lint:container`.

## 2. Master key provisioning and backup

`WECHAT_CREDENTIAL_KEY` is a base64-encoded 32-byte master key. It must never be
committed, logged, or stored next to database backups.

Generate:

```sh
openssl rand -base64 32
```

Set it in the production environment (not in any tracked `.env` file).
Back up the key in the approved secret store, separately from database backups,
and record who can restore it. If the key is lost, stored credentials cannot be
decrypted and every operation fails closed; there is no recovery except restoring
the key from backup.

Required history must remain decryptable: before deleting an old key from the
secret store, run `app:wechat:rotate-key` (Section 3) so all payloads use the new
key id.

## 3. Credential rotation

Re-encrypt every stored credential under a replacement key:

```sh
# Report only.
php integration/backend/bin/console app:wechat:rotate-key --new-key='<base64>' --dry-run
# Apply.
php integration/backend/bin/console app:wechat:rotate-key --new-key='<base64>'
```

The command decrypts with the configured current key and writes payloads encrypted
with `--new-key`, updating key ids. Payloads that fail to decrypt are reported
and abort the run without partial writes being committed for those rows (fix the
key or the data, then retry). After a successful run, switch
`WECHAT_CREDENTIAL_KEY` to the new value (no restart is needed for correctness of
new resolutions, but recycle workers to drop old in-memory clients), then verify
with `app:wechat:probe-credentials` and reconcile pending items (Section 5).

## 4. Probes, revocation, and disabling

Offline readiness probe (decryption and PEM parsing only; never contacts WeChat):

```sh
php integration/backend/bin/console app:wechat:probe-credentials application <revision-uuid>
php integration/backend/bin/console app:wechat:probe-credentials merchant <revision-uuid>
```

Disable (stops new operations; historical callbacks and reconciliation keep working):

- Application/merchant profile: set `enabled: false` via the Admin API.
- Store binding: set `enabled: false` on the store setting.

Hard revocation (compromised credentials; blocks new AND historical operations):

```sh
php integration/backend/bin/console app:wechat:revoke application <revision-uuid> --reason='...'
php integration/backend/bin/console app:wechat:revoke merchant <revision-uuid> --reason='...'
```

After revoking a merchant revision, pending refunds and reconciliations that need
it will fail closed. Refunds for affected attempts require an authorized
same-merchant replacement credential (rebind the store to a new revision of the
same merchant identity); never switch merchants for an existing attempt.

## 5. Reconciliation

Recover missed callbacks and ambiguous provider results:

```sh
php integration/backend/bin/console app:wechat:reconcile --limit=50 --grace-minutes=30
```

- Unresolved payment attempts are queried by provider order number and confirmed
  through the same verification path as notifications.
- Provider-unknown orders stay pending for operator review, except never-sent
  `created` attempts older than the grace period, which close without charging.
- Pending refunds are queried by provider refund number; verified successes apply
  accounting exactly once.

Investigate operator-review items with redacted correlation data:

- Payment attempt UUID, provider order/transaction numbers, expected amount.
- Refund attempt UUID, provider refund number, status history.
- Audit events: `SELECT action, object_type, object_uuid, result, created_at FROM wechat_audit_event WHERE store_uuid = ? ORDER BY id DESC;`

Secrets never appear in audit context (values are redacted at write time).

## 6. Legacy transactions

Pre-existing global-merchant transactions keep working through the frozen legacy
path: explicit `legacy: true` payment/refund options and locator-less callbacks on
`/api/payment/notify/wechat`. Do not route new store payments there; the store
gateway fails closed when no binding exists.

Before refunding a legacy invoice, confirm its merchant/application provenance.
Ambiguous rows require operator resolution; never guess.

## 7. Rollback

- Disable new dynamic operations (binding `enabled: false`) or republish a
  previous safe binding.
- Rolling back code does not drop tables, delete identities/attempts, or stop
  historical callbacks and reconciliation. Retain a compatible code version.
- Credential revisions and audit events are append-only history; keep them while
  any payment or refund may still need reconciliation.

## 8. Live smoke tests (requires explicit approval)

Only with approved test applications/merchants, an agreed charge ceiling, and a
refund/reconciliation owner. Never run live financial tests automatically in CI.

1. Mini Program login and phone binding against the approved app.
2. Official Account OAuth against the approved app and allowlisted redirect.
3. Native payment for a small amount; confirm the callback marks the invoice paid.
4. JSAPI payment for a small amount; confirm the signed payload and callback.
5. Full refund of the test payment; confirm the refund callback/query marks the
   invoice refunded exactly once.
6. Record provider order/transaction/refund numbers and redacted evidence in the
   release report.
