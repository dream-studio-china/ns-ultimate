export const printerTemplateFieldsArticle = {
  id: 'ns-printer-template-fields',
  title: 'Printer template data fields',
  module: 'Printer',
  kind: 'reference',
  locale: 'en',
  aliases: ['printer template fields', 'print data fields', 'order snapshot fields', 'counterName', 'counterLabel', 'labelHeader', 'quantityLabel', '打印字段', '可用字段', '模板字段', '订单快照', '桌台号', '打印数据'],
  sourcePath: 'business/backend/Printer/README.md',
  content: [
    'Field contract: use only the paths below for real print templates. The offline sample-data JSON is editor scaffolding for previews and is not a promise of real fields.',
    'Order: order.id (numeric database ID; order.number is the order number), order.uuid, order.number, order.status, order.counterName, order.counterLabel, order.labelHeader, order.createdAt, order.createdTime (Y/m/d H:i:s), order.paidAt, order.currency, order.totalAmount, order.totalPrice, order.discount, order.amountDue, order.paymentUrl, order.items. Store: store.uuid, store.name, store.code.',
    'Items inside order.items, and the top-level item on per_unit tickets: name, product, specification, spec, subTitle (the current product metadata subtitle, falling back to the specification name), quantity, quantityLabel (x plus the ordered line quantity), unitPrice, amount, price, productUuid, productSettings; per_unit tickets add item.index and item.total.',
    'Counters: counterName and labelHeader come from the order counter binding name snapshot ordered by binding ID, joined with a comma, and empty when the order has no binding; counterLabel appends (#id). Later counter renames do not rewrite saved snapshots.',
    'Money: unitPrice, amount, price and totalAmount are integer minor units paired with order.currency. totalPrice (line subtotal), discount (totalPrice minus amountDue, never negative) and amountDue (stored totalAmount) are ready-to-print strings with the currency symbol and fraction digits. paymentUrl is the customer H5 payment link built from the backend public origin (DEFAULT_URI) as /h5/payment/p.html?o=<order uuid>; it is empty when that origin is not configured, so guard a QR with an if node.',
    'Missing by design: staffName, bookingName, waiterName and their labels, isAdd, laterPay and item.tags are demo-only. Templates referencing them print empty values for real orders, so do not fabricate replacements or map them from arbitrary order metadata.',
    'Freshness: only newly built print requests gain newer fields; existing queued requests and job reprints keep their saved snapshot.'
  ].join('\n')
}
