export const printerAutoPrintingArticle = {
  id: 'ns-printer-auto-printing',
  title: 'Automatic Trade order printing',
  module: 'Printer',
  kind: 'guide',
  locale: 'en',
  aliases: ['automatic printing', 'auto print', 'order printing', 'kitchen printer', 'settlement receipt', '自动打印', '后厨打印', '结账小票', '订单打印'],
  sourcePath: 'business/backend/Printer/README.md',
  content: [
    'Supported: configure store-scoped Printer order rules with events, conditions, device, template and copies. Rules match confirmed, paid or accepted events and can test order status or nested order metadata with equals, not_equals, exists or not_exists; match all or any conditions. The selected template owns the output scenario: ticketMode is single (one ticket per order) or per_unit (one per unit), and itemFilter is all or kitchen (only Product.settings.kitchenPrinterEnable items, skipping when none match).',
    'Migration: legacy Store.settings.printing is no longer used for automatic printing. Existing stored values are not deleted or automatically migrated; recreate and verify the desired order rules explicitly.',
    'Dispatch: requests are persisted with order/product data snapshots before dispatch, so event handlers never call printer providers directly. Run app:printer:dispatch-orders periodically (or as a supervised worker command) to submit pending requests. Request keys are stable across redelivery and an ambiguous provider result is never automatically resubmitted; failed requests stay visible in printer_order_request for operator investigation.',
    'Offline demo: app:printer:seed-dummy works in dev and test environments only and persists one disabled fake account, two disabled demo devices, two demo templates and two locally rendered sample jobs with terminal failed status. These are UI examples, never actual print attempts; do not enable the fake account or devices, and use real account/device setup for live printing.',
    'Assistant boundary: use the configured order-rule administration surface; do not suggest editing Store.settings.printing. The assistant has no console, provider-status, print-submission or reprint tool. Investigate duplicate or missing tickets using printer_order_request records and the normal job list.',
  ].join('\n')
}
