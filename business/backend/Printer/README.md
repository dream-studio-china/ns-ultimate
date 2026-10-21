# Printer module

The module currently provides a print workbench preview endpoint and a restricted,
data-driven renderer for receipt and label content. Receipt and label templates
are separate; `each` nodes can expand order items or other arrays, and `repeat`
nodes can produce one label per integer item quantity, and `if` nodes can
conditionally render optional fields. Templates do not execute PHP or
JavaScript. Loop input is capped at 100 items, nesting at five levels, and
output at 200 pages. A submission is capped at 500 physical copies/pages after
combining page expansion with the requested copy count.

## Configuration

Create the Xpyun developer account through the admin API. Its developer key is
encrypted at rest with `PRINTER_CONFIG_KEY`. Configure a
private, stable encryption key before creating accounts and do not commit it.
Losing or changing the key makes saved account credentials unreadable. The
client uses the platform's fixed HTTPS API host and JSON requests. No credentials
are returned to the frontend.

Apply the `Printer` module migration after configuring the database. Management
routes under `/api/v1/manage/printer` require `ROLE_ADMIN`. Create an account,
register devices, save templates, submit print jobs with a unique
`idempotencyKey`, and query device/job status. The job endpoint persists a
snapshot before calling Xpyun; ambiguous transport outcomes are recorded as
`unknown` and are never automatically resubmitted.

`GET /api/v1/manage/printer/jobs/{id}/preview` returns the stored job's
`document` and `copies` in the standard success envelope, without re-rendering
or contacting a provider. Document pages come from the saved print content;
dimensions come from the template snapshot. Both this endpoint and the POST
workbench preview include `widthMm` and `heightMm`: receipts default to 80 mm
wide with variable height (`null`), and labels default to 40 x 30 mm.

## Offline demo data (dev/test only)

After configuring a local development/test database and applying the module
migrations, run from the repository root:

```sh
php integration/backend/bin/console app:printer:seed-dummy
```

The command refuses environments other than `dev` and `test`. It directly
persists one disabled fake account (empty credentials), two disabled devices
(receipt and 50 x 40 mm label), two usable demo templates, and two locally rendered
sample jobs. Jobs have terminal `failed` status with `local_demo_not_submitted`
and no provider order IDs; they are UI examples, not actual print attempts.
No provider APIs or credential encryption keys are used. Re-running reuses the
reserved `local-printer-demo` account, device serials, template names, and job
idempotency keys without duplicating records or overwriting existing content.
Do not enable the fake account or devices; the command refuses changed/enabled
reserved hardware records. Use real account/device setup for live printing.

## Admin integration

The admin menu uses the project's EasyAdmin entity configuration for accounts,
devices, templates, and jobs. Their read/detail endpoints use `RestController`
with the core `ApiView`, `ListApiViewMixin`, and `DetailApiViewMixin` lifecycle.
Provider operations (credential entry, device registration/status, preview, and
print submission/status) are exposed through a configured top action, not a
standalone route view. Preview never submits a print job.

Registration and job dialogs use labeled forms, loading guards and separate
error/result alerts. Job templates are filtered to match the selected device
kind; without enabled devices, templates can still be previewed locally. The
preview and submit handlers unwrap the shared request helper's `data` envelope.
Disabled devices/accounts cannot be queried for provider status, and toolbar
refresh skips disabled demo devices. Live submission requires confirmation.

## DeliCloud protocol support

`DelicloudPrintProtocol` implements message construction and validation for the
[DeliCloud Cloud Print IoT protocol V1.9](https://doc.delicloud.com/v3/iot/print/): device-info queries, print-task
notifications, V2 task-pull messages, same-context acknowledgements, and device
information/print-progress report parsing. DeliCloud's documented flow is
device-to-cloud messaging: the device pulls converted HTTPS print files and
reports progress. This codec deliberately does not claim to submit jobs: MQTT/
HTTP platform transport, DeliCloud application-side task conversion/file upload,
application credentials, and platform test-environment access are not part of
the supplied protocol page. The current EasyAdmin print-submission operation
remains Xpyun-only; DeliCloud devices are not enabled for live submission until
those integration details are available.

The Xpyun adapter exposes receipt (`print`) and label (`printLabel`) separately.
Xpyun currently documents label support for XP-T271U and XP-320B; verify the
physical model before sending label jobs. Management is currently admin-only;
store-scoped permissions, template editing beyond the workbench's create flow,
retries/reprints, and live printer rendering remain follow-up work.
