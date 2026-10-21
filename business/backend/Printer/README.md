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

## Receipt and label layout

Layout nodes are compiled to the allowlisted commands documented in the
[Xpyun receipt and label API](https://www.xpyun.net/open/index.html). Existing
templates keep their previous output, except that `qr` on a receipt now emits
the documented `QRCODE` command instead of the label-only `QRC` command, and
receipt loops now append their item lines within one ticket instead of
duplicating a surrounding header and footer for every item. Label loops retain
their individual-page behavior. Existing saved job content is never rewritten.

- `style` (receipt only): `align` is `left`, `center`, or `right`; `width` and
  `height` are independent integer multipliers from 1 to 2; `bold` is boolean.
  `children` contain regular nodes. A terminating line break is inserted inside
  the paired alignment/size commands, as required by the provider. Size and
  emphasis are distinct: `B` enlarges text, while `BOLD` makes it bold.
- `receiptCommand` (receipt only): `value` is one allowlisted formatting tag
  (`L/C/R/N/HB/WB/B/BOLD/CB`, opening or closing, or `BR`). This preserves
  legacy stateful/unpaired commands without adding closing tags or line breaks.
  Compose literal LF characters using `text`, and dynamic content using escaped
  `field` nodes. This is not arbitrary printer markup or executable code.
- `columns` (receipt only): `columns` contains 1-8 cells, each with `width`
  (display cells), `align`, and either `path` or `value`. Text is truncated and
  padded by Unicode display width before escaping. CJK characters occupy two
  cells. Total widths are capped at 96; choose widths that fit the physical
  printer (normally 32 cells for 58 mm or 48 for 80 mm before width scaling).
  Format amounts upstream; templates do not perform monetary calculations.
- `labelText`: accepts either `path` or literal `value`, font 1-9, `w`/`h`
  multipliers 1-10, and `r` rotation 0/90/180/270. Optional `align` computes the
  x coordinate for unrotated font 9: ASCII cells are 12 dots, CJK cells 24 dots,
  multiplied by `w`. Center/right alignment uses the configured label width
  at 8 dots/mm and clamps overlong text to x=0, matching the legacy behavior.
  Long text can still clip; physical fitting is not guaranteed.
  Set `widthMode: "legacy"` with `align` to reproduce `OrderService::labelCenterX`:
  trim only for measurement and count every multibyte code point as two
  half-cells, including accented letters. The emitted text remains unchanged.
  The default `display` mode retains Unicode display-width measurement.
- `qr`: accepts `path`, `size`, `errorCorrection` (`L/M/Q/H`). Receipts use
  `size` 0-16 (default 6) and `align` (default `center`); whitespace in receipt
  QR payloads is rejected as required by Xpyun. Labels use `size` 1-10 (default
  2), `x`/`y` and UTF-8 `QRC`. Both kinds cap content at 256 characters. Payment
  URLs must come from trusted application data, never from hardcoded live links.

The paper preview interprets these commands without injecting HTML and renders
QR matrices locally. Fonts, line wrapping and QR sizing are approximations;
verify the target model, paper width and actual output before production use.
Preview receipt wrapping uses 32 display cells for widths up to 58 mm and 48
for wider receipts, with fixed 12-dot ASCII/24-dot CJK character pitch rather
than scaling fonts with paper width. Receipt glyph height is estimated at 24
dots with a 6-dot interline gap; line spacing and feed margins still require
model-specific physical calibration. Label fonts 1-9 use their documented dot
cell dimensions. Browser glyph shapes are not printer bitmap fonts. Receipt QR
size 0 (provider auto-sizing) is estimated using size 6 in the preview.
Mixed receipt sizes share a bottom-aligned row whose height follows the tallest
glyph box, with the interline gap added once. Enlarged cells cannot shrink to
fit: excess text wraps at the printable column boundary. Preview-only support
for `HB2`/`WB2`/`B2` uses three native cells on the enlarged axes; these tags
do not imply bold. This does not change the legacy templates or their commands.
Category filtering, promotion selection, amount calculation, payment status and
per-unit receipt dispatch remain caller responsibilities, not template rules.

### Legacy template fidelity

The local `Legacy -` templates follow `MainBundle\Service\OrderService`'s
`sendToPrinter`, `renderScene1Ticket`, `renderScene1LabelTicket`,
`renderSceneListTicket`, and `renderSettleTicket` output. Preserve their command
order, literal line breaks, punctuation and size tags; do not substitute
`BOLD` for `B`, add automatic `BR` nodes, or rewrite unpaired commands as
`style` blocks. Labels opt into `labelEnvelope: "legacy"` to preserve the
original `PAGE` envelope, indentation and absence of `SIZE`; the three `TEXT`
commands use the legacy coordinates and centering algorithm. Other labels keep
the default `sized` envelope. Omitting `SIZE` intentionally follows the old
code, not the current provider recommendation; verify label feeding on hardware.
Fields must carry legacy-formatted data (including currency, short uppercase
order numbers, and the correct names rather than annotated scene labels).
Payment URLs remain supplied data rather than a hardcoded production host.
These local template records do not migrate or rewrite existing saved jobs.

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
store-scoped permissions and live printer render calibration remain follow-up
work. Templates can be edited through the configured CRUD lifecycle. Reprints
create a new job from the immutable stored snapshot after explicit frontend
confirmation; `unknown` and `submitting` jobs are rejected to prevent
accidental duplicate output. A reprint uses a fresh provider idempotency key
and the source copy count, and requires the source device and account to be
enabled.
