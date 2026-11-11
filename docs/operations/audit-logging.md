# API audit logging

## Purpose and location

The composed backend writes a persistent audit log separate from the default
Monolog handlers. Production Monolog normally emits to `php://stderr`, which
depends on PHP-FPM log capture and is hard to search for a single provider
callback. The project `audit` channel instead writes rotating JSON lines to:

```text
<project-root>/var/log/backend/audit-YYYY-MM-DD.log
```

`rotating_file` keeps 30 daily files. Set `AUDIT_LOG_PATH` to a different
absolute path when the deployment requires another location. The path is
resolved at runtime, so no container rebuild is needed.

`bash scripts/deploy.sh --backend` creates `var/log/backend` with the configured
`PHP_FPM_USER`/`PHP_FPM_GROUP` ownership. A deployment that does not use the
script must create a writable directory for the runtime account. Do not make the
repository or its parent directories writable.

The upstream `access` channel remains enabled. It records POST/PUT/DELETE only,
caps bodies at 4 KiB and excludes authentication routes; the audit channel is
the complete project record.

## Recorded fields

Every main request whose path starts with `/api` or `/wechat` writes one
`api_request` entry after the response:

| Field | Meaning |
| --- | --- |
| `method`, `path`, `query`, `route` | Request target and matched route name |
| `status`, `durationMs` | Response outcome and wall-clock duration |
| `user`, `ip` | Authenticated subject identity and client address |
| `requestId` | Caller-supplied `X-Request-Id`, when present |
| `requestHeaders` | All request headers except credential-carrying ones |
| `requestBody` | Write-method content: POST, PUT, PATCH, DELETE |
| `responseBody` | Write-method content and every error response |
| `exception` | Exception class and message when the request failed |

Bodies are UTF-8 JSON or form data. Binary content is recorded as a byte count.
Content beyond 1 MiB is truncated with an explicit `...[truncated]` marker so a
large payload cannot exhaust the disk.

Credential fields are replaced with `[redacted]`, and `Authorization`, `Cookie`,
`Proxy-Authorization` and `X-Api-Key` request headers are omitted. Repository
policy forbids writing real secrets or tokens to logs. This is the only masking
applied; other business fields and provider payloads are recorded verbatim.

The redaction list is known to be incomplete. It does not cover every credential
field accepted by the project: administrator requests that store WeChat `secret`,
`aesKey`/`aes_key`, or `apiV3Key` values are currently recorded verbatim in the
request and response bodies. Query strings are also logged unchanged. Until the
list is expanded, treat the audit file as containing credential material, restrict
read access to operators, and avoid copying raw entries from credential-management
or payment requests into tickets. Rotate a credential that is believed to have
been exposed through the log.

The log contains full business payloads, including customer data and signed
provider messages. Restrict read access to operators, keep it inside the backup
and retention policy, and do not paste raw entries into public tickets.

## Huifu payment diagnostics

The same channel records the payment lifecycle:

| Message | When |
| --- | --- |
| `huifu_request` | Outbound provider request, including `notify_url` and signed data |
| `huifu_response` | Provider HTTP status, body and duration |
| `huifu_request_failed` | Transport failure before a provider response |
| `huifu_notification_processed` | Accepted callback and acknowledgement |
| `huifu_notification_rejected` | Callback failed verification; includes the precise reason |
| `huifu_notification_failed` | Callback failed during processing; includes exception class |
| `huifu_pos_notification_processed` | Accepted POS receipt notification |
| `huifu_pos_notification_rejected` | POS receipt notification failed verification |
| `huifu_pos_notification_failed` | POS receipt notification failed during processing |

A rejected callback still returns the generic `FAIL` body so Huifu retries and
callers learn nothing. The reason is only in the audit entry. To investigate a
specific payment, search by the attempt UUID, then read the `api_request` entry
for the callback route to see the exact request body:

```sh
grep '<attempt-uuid>' var/log/backend/audit-*.log
grep -F '/api/payment/notify/huifu/<attempt-uuid>' var/log/backend/audit-*.log
```

After correcting the cause, ask Huifu to resend the original callback. Do not
create a new payment to retest a callback. `app:huifu:reconcile` queries pending
attempts and refunds; it is a compensation command, not a single-callback retry.

### Huifu refund recovery

If Huifu reports a completed refund while the unified API reports `unknown`, do not submit another refund, change the idempotency key, clear the receipt or mark database rows successful manually. Inspect the existing `huifu_refund` row and the audited `/v2/trade/payment/scanpay/refund` and `/refundquery` requests/responses. The row's `response_code`, `response_message`, allowlisted `response_snapshot`, `query_count`, `last_queried_at` and `next_query_at` distinguish verified provider results from query transport/verification failures. Unverified query bodies are not copied into the refund snapshot; protected audit logs remain the source for raw responses.

After deploying the corrected query implementation, run from the project root using the production PHP/runtime configuration:

```sh
APP_ENV=prod APP_DEBUG=0 php integration/backend/bin/console app:huifu:reconcile --refund=<huifu-refund-uuid>
```

The argument is the existing **HuifuRefund UUID**, not the unified refund receipt UUID, Trade Order UUID, StoreOrder UUID or refund request sequence. Resolve it from the existing provider record associated with the receipt before execution. This mode processes no other payments/refunds and ignores the selected row's next-query time. It only queries the original refund or reapplies an already-confirmed result through Core's idempotent workflow; it never calls the refund-submission endpoint. Created, failed, cancelled, missing or malformed selections are rejected. A repeated completed recovery does not issue another provider query or duplicate the refunded amount.

The same targeted operation is available as `POST /api/v1/manage/huifu-refunds/{huifuRefundUuid}/reconcile` for administrators and `POST /api/v1/store/{storeUuid}/huifu-refunds/{huifuRefundUuid}/reconcile` for a Store operator with `store:order:refund`. These endpoints query only the specified persisted Huifu refund and never submit a new refund request.

The command prints the persisted status and `coreApplied` marker. Exit code 0 means Core application is complete, 1 means confirmation/application failed or remains unresolved, and 2 means an invalid selection. Do not treat a query's successful HTTP response as refund completion. Refresh the unified refund detail/history and Invoice balance after recovery. Partial refunds leave the Trade order `paid`; cumulative full refunds change it to `refunded` through the normal Core flow. If confirmation remains unknown, investigate the protected response rather than resubmitting.

For unattended compensation, schedule `app:huifu:reconcile --limit=50` externally with the production runtime and monitor its logs. The repository does not start this scheduler. Targeted recovery does not recover a metadata-only reservation whose provider attempt was never persisted.

## Limits

Logging does not change authorization, response bodies or HTTP status codes. It
must not be treated as proof of payment, an authentication decision or an
immutable ledger. A logging failure does not fail the request, and rotating
files may be lost if the runtime directory is not persistent. Validate access
controls and retention for the deployment's actual storage.
