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

## Admin integration

The admin menu uses the project's EasyAdmin entity configuration for accounts,
devices, templates, and jobs. Their read/detail endpoints use `RestController`
with the core `ApiView`, `ListApiViewMixin`, and `DetailApiViewMixin` lifecycle.
Provider operations (credential entry, device registration/status, preview, and
print submission/status) are exposed through a configured top action, not a
standalone route view. Preview never submits a print job.

The Xpyun adapter exposes receipt (`print`) and label (`printLabel`) separately.
Xpyun currently documents label support for XP-T271U and XP-320B; verify the
physical model before sending label jobs. Management is currently admin-only;
store-scoped permissions, template editing beyond the workbench's create flow,
retries/reprints, and live printer rendering remain follow-up work.
