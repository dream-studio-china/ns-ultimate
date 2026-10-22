export const printerTemplatesArticle = {
  id: 'ns-printer-templates',
  title: 'Printer templates and paper preview',
  module: 'Printer',
  kind: 'guide',
  locale: 'en',
  aliases: ['printer template', 'receipt template', 'label template', 'paper preview', 'print preview', '打印模板', '小票模板', '标签模板', '纸张预览'],
  sourcePath: 'business/backend/Printer/README.md',
  content: [
    'Supported: receipt and label templates are separate. Templates are data-driven layout nodes with each (expand order items or arrays), repeat (one label per integer item quantity), and if (conditional fields). Templates do not execute PHP or JavaScript. Limits: loop input capped at 100 items, nesting at five levels, output at 200 pages, and a submission at 500 physical copies/pages.',
    'Editing: a template needs a name, a kind (receipt or label), a JSON definition and sample-data JSON; the sample data validates the template before saving. Templates can be edited through the configured CRUD lifecycle, but template deletion is disabled in the list. When submitting a job, templates are filtered to match the selected device kind; without enabled devices, templates can still be previewed locally.',
    'Preview: the paper preview interprets provider commands without injecting HTML and renders QR matrices locally. It is approximate: fonts, line wrapping and QR sizing do not match printer bitmaps, receipt wrapping uses 32 display cells for widths up to 58 mm and 48 above, and receipt QR size 0 is estimated as size 6. Both preview endpoints include widthMm and heightMm; receipts default to 80 mm wide with variable height (null) and labels default to 40 x 30 mm. The job preview endpoint returns the stored document and copies without re-rendering or contacting a provider. Always verify the target model, paper width and physical output before production use.',
    'Layout notes: receipt text uses paired align/size commands where B enlarges and BOLD makes bold; receiptCommand preserves legacy stateful tags without adding closing tags; columns hold 1-8 cells with total widths capped at 96 display cells (normally 32 for 58 mm, 48 for 80 mm); QR content is capped at 256 characters and whitespace in receipt QR payloads is rejected. Xpyun documents label support for XP-T271U and XP-320B, so verify the physical model before sending label jobs.',
    'Assistant boundary: the assistant cannot render previews or submit jobs on your behalf. It can explain template structure and limits; all preview and submit actions are manual operator steps in the Admin UI.'
  ].join('\n')
}
