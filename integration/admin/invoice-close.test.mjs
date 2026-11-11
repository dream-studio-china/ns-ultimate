import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import test from 'node:test'
import en from '../../business/admin/i18n/en.js'
import zh from '../../business/admin/i18n/zh.js'
import zhHant from '../../business/admin/i18n/zh-Hant.js'
import ja from '../../business/admin/i18n/ja.js'
import {
  buildInvoiceCloseRequest,
  createInvoiceCloseCommandUuid,
  invoiceCloseContext,
  invoiceCloseFailureKind,
  invoiceCloseReasonError,
  invoiceCloseSucceeded,
  invoiceDetailPath,
  orderPaymentClosePath,
  preliminaryInvoiceCloseable
} from '../../business/admin/config/components/invoice-close.mjs'

const invoiceUuid = '00000000-0000-4000-8000-000000000010'
const orderUuid = '00000000-0000-4000-8000-000000000001'
const commandUuid = '00000000-0000-4000-8000-000000000020'
const invoice = {
  id: 10,
  uuid: invoiceUuid,
  sourceType: 'trade_order',
  sourceId: orderUuid,
  status: 'paying'
}

test('targets the existing manage order payment closure endpoint', () => {
  assert.equal(invoiceDetailPath(10), '/api/v1/manage/invoices/10')
  assert.equal(
    orderPaymentClosePath(orderUuid),
    `/api/v1/manage/orders/${orderUuid}/payment/close`
  )
  assert.throws(() => orderPaymentClosePath('not-an-order'), /Trade order UUID is required/)
})

test('allows closure only for unsettled Trade order invoices', () => {
  for (const status of ['pending', 'paying', 'failed']) {
    assert.deepEqual(invoiceCloseContext({ ...invoice, status }), { error: '', canClose: true })
    assert.equal(preliminaryInvoiceCloseable({ ...invoice, status }), true)
  }
  assert.equal(invoiceCloseContext({ ...invoice, status: 'cancelled' }).error, 'invoiceCloseAlreadyClosed')
  assert.equal(invoiceCloseContext({ ...invoice, status: 'paid' }).error, 'invoiceCloseSettled')
  assert.equal(invoiceCloseContext({ ...invoice, status: 'refunded' }).error, 'invoiceCloseSettled')
  assert.equal(invoiceCloseContext({ ...invoice, status: 'unknown' }).error, 'invoiceCloseUnavailable')
  assert.equal(invoiceCloseContext({ ...invoice, sourceType: 'wallet_topup' }).error, 'invoiceCloseNotTradeOrder')
  assert.equal(invoiceCloseContext({ ...invoice, sourceId: 'invalid' }).error, 'invoiceCloseOrderMissing')
})

test('builds the exact closure body and preserves command identity for retries', () => {
  assert.deepEqual(buildInvoiceCloseRequest(invoice, commandUuid, '  Admin requested closure  '), {
    commandUuid,
    invoiceUuid,
    reason: 'Admin requested closure'
  })
  assert.equal(invoiceCloseReasonError(''), 'invoiceCloseReasonRequired')
  assert.equal(invoiceCloseReasonError('x'.repeat(1001)), 'invoiceCloseReasonTooLong')
  assert.equal(invoiceCloseReasonError('退款'), '')
})

test('requires an explicit released result and classifies safe failure outcomes', () => {
  assert.equal(invoiceCloseSucceeded({ data: { status: 'closed', released: true } }), true)
  assert.equal(invoiceCloseSucceeded({ data: { status: 'closed', released: false } }), false)
  assert.equal(invoiceCloseSucceeded({ data: { status: 'paid', released: false } }), false)
  assert.equal(invoiceCloseFailureKind({ response: { status: 409, data: { message: 'payment_already_paid' } } }), 'paymentAlreadyPaid')
  assert.equal(invoiceCloseFailureKind({
    response: { status: 409, data: { message: 'payment_already_paid', data: { requiresReview: true } } }
  }), 'paymentRequiresReview')
  assert.equal(invoiceCloseFailureKind({ response: { status: 409, data: { message: 'payment_close_unconfirmed' } } }), 'unconfirmed')
  assert.equal(invoiceCloseFailureKind({ response: { status: 502, data: { message: 'payment_close_failed' } } }), 'channelFailed')
  assert.equal(invoiceCloseFailureKind(new Error('timeout')), 'unknown')
})

test('generates RFC 4122 command identifiers for closure audit records', () => {
  const generated = createInvoiceCloseCommandUuid({ randomUUID: () => commandUuid })
  assert.equal(generated, commandUuid)
  assert.match(createInvoiceCloseCommandUuid(null), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
})

test('registers closure actions on the Invoice list and detail page', () => {
  const source = readFileSync(new URL(
    '../../business/admin/config/overrides/payment/invoice.js', import.meta.url
  ), 'utf8').replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const base = {
    list: { disabled_actions: ['new', 'delete'], actions: [], list_display: ['id'] },
    detail: { detail_display: [{ property: 'amount' }, '__all__'] }
  }
  const context = {
    Invoice: { Invoice: base },
    InvoiceCloseAction: 'InvoiceCloseAction',
    t: key => key
  }
  runInNewContext(source, context)

  const config = context.config
  assert.deepEqual(Array.from(config.list.actions, action => action.name), ['invoice-close'])
  assert.equal(config.list.actions[0].position, 'list')
  assert.equal(config.list.actions[0].component, 'InvoiceCloseAction')
  assert.deepEqual(Array.from(config.detail.detail_display, field => field.property || field), [
    'amount', 'paymentClosure', '__all__'
  ])
  assert.equal(config.detail.detail_display[1].component, 'InvoiceCloseAction')
  assert.equal(config.detail.detail_display[1].full_width, true)
})

test('localizes every invoice closure message in all business locales', () => {
  const keys = [
    'Invoice closure',
    'Close Invoice',
    'Only pending, processing, or failed Trade order invoices can be closed',
    'Invoice closure help',
    'Invoice closure reason',
    'Enter invoice closure reason',
    'Retry invoice closure',
    'Invoice closed from the admin invoice page.',
    'Confirm invoice closure',
    'Invoice closed and released from the order.',
    'The invoice could not be closed because its order or payment state changed. Refresh and verify before retrying.',
    'Invoice payment was already completed. It was not closed.',
    'A payment was discovered while closing this invoice and requires review. The invoice was not released.',
    'Invoice closure failed. The invoice was not treated as closed.',
    'Invoice closure result is unknown. Refresh and verify the payment status before trying again.',
    'Failed to load invoice closure details',
    'invoiceCloseNotTradeOrder',
    'invoiceCloseOrderMissing',
    'invoiceCloseAlreadyClosed',
    'invoiceCloseSettled',
    'invoiceCloseUnavailable',
    'invoiceCloseReasonRequired',
    'invoiceCloseReasonTooLong',
    'Processing'
  ]
  for (const messages of [en, zh, zhHant, ja]) {
    for (const key of keys) assert.equal(typeof messages[key], 'string', key)
  }
})
