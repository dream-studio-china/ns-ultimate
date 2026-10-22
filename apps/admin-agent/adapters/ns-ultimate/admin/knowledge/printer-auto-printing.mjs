export const printerAutoPrintingArticle = {
  id: 'ns-printer-auto-printing',
  title: 'Automatic Trade order printing',
  module: 'Printer',
  kind: 'guide',
  locale: 'en',
  aliases: ['automatic printing', 'auto print', 'order printing', 'kitchen printer', 'settlement receipt', '自动打印', '后厨打印', '结账小票', '订单打印'],
  sourcePath: 'business/backend/Printer/README.md',
  content: [
    'Supported: the Printer module can enqueue order print requests for store-scoped Trade orders. Configure the five fixed legacy cases in Store.settings.printing: order, perItem, filteredList, fullList and settlement. Each case has its own enabled flag, printer deviceUuid, templateUuid and optional copies (default 1, schema range 1-100). The adapter selects the print behavior and timing; the store only enables a case and chooses its device and template. The cases map to the legacy normal order receipt, per-item ticket, kitchen-filtered list, full item list and settlement receipt.',
    'Timing: production cases run after store acceptance when Store.settings.order.requireAcceptance is true, and otherwise after payment. Settlement runs only after payment. The kitchen-filtered list honors the product settings.kitchenPrinterEnable flag.',
    'Dispatch: requests are persisted with order/product data snapshots before dispatch, so event handlers never call printer providers directly. Run app:printer:dispatch-orders periodically (or as a supervised worker command) to submit pending requests. Request keys are stable across redelivery and an ambiguous provider result is never automatically resubmitted; failed requests stay visible in printer_order_request for operator investigation.',
    'Offline demo: app:printer:seed-dummy works in dev and test environments only and persists one disabled fake account, two disabled demo devices, two demo templates and two locally rendered sample jobs with terminal failed status. These are UI examples, never actual print attempts; do not enable the fake account or devices, and use real account/device setup for live printing.',
    'Assistant boundary: enabling print cases, choosing devices and templates, and running the dispatch command are manual operator steps. The assistant cannot run console commands, change store settings, or submit print requests; unexpected duplicate or missing tickets should be investigated in the printer_order_request records and job list.'
  ].join('\n')
}
