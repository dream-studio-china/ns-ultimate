# Printer module

The module currently provides a print workbench preview endpoint and a restricted,
data-driven renderer for receipt and label content. Receipt and label templates
are separate; `each` nodes can expand order items or other arrays. Templates do
not execute PHP or JavaScript. Loop input is capped at 100 items, nesting at five
levels, and output at 200 pages.

## Configuration

Set `XPYUN_USER` and `XPYUN_USER_KEY` in the local deployment environment before
using the Xpyun API client. Never commit these values. The client uses the
platform's fixed HTTPS API host and JSON requests. No credentials are returned
to the frontend.

## Preview workbench

Administrators can open **Printer workbench** to try a receipt with repeated
order items or batch label data. The preview API is
`POST /api/v1/manage/printer/preview`; it returns rendered pages, provider
command text, JavaScript/cURL examples, and a warning that physical output can
differ. Preview never submits a print job.

The Xpyun adapter exposes receipt (`print`) and label (`printLabel`) separately.
Xpyun currently documents label support for XP-T271U and XP-320B; verify the
physical model before sending label jobs. Device/account management, durable job
tracking, printing permissions, and live printer rendering remain follow-up
work and are not implied by the preview workbench.
