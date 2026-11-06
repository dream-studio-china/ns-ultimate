import { orderRefundHistoryPath } from './order-refund.mjs'

export const ORDER_REFUND_ORDERS_ENDPOINT = '/api/v1/manage/orders'
export const ORDER_REFUND_ORDERS_PAGE_SIZE = 100
export const ORDER_REFUND_HISTORY_CONCURRENCY = 8

const REFUND_REQUESTS_KEY = 'storeRefundRequests'
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

export function orderRefundOrdersParams(page = 1, limit = ORDER_REFUND_ORDERS_PAGE_SIZE) {
  return {
    page: Math.max(1, Number(page) || 1),
    limit: Math.min(100, Math.max(1, Number(limit) || ORDER_REFUND_ORDERS_PAGE_SIZE))
  }
}

export function normalizeOrderRefundOrdersResponse(payload) {
  if (!payload || !Array.isArray(payload.data)) throw new Error('Unexpected order list response')

  const page = Number(payload.paginator?.page ?? 1)
  const pages = Number(payload.paginator?.pages ?? 1)
  if (![page, pages].every(Number.isSafeInteger) || page < 1 || pages < 1) {
    throw new Error('Invalid order list paginator')
  }

  return {
    items: payload.data,
    page,
    pages,
    hasNext: payload.paginator?.has_next === true
  }
}

export function orderRefundContexts(orders) {
  if (!Array.isArray(orders)) throw new Error('Unexpected order collection')

  const contexts = []
  for (const order of orders) {
    const entries = order?.metadata?.storeRefundRequests
    if (!entries || typeof entries !== 'object' || Array.isArray(entries) || Object.keys(entries).length === 0) continue

    const storeUuid = order?.metadata?._store?.uuid
    if (typeof storeUuid !== 'string' || !UUID_PATTERN.test(storeUuid)) {
      throw new Error(`Store context is missing for order ${order?.uuid || 'unknown'}`)
    }
    if (typeof order.uuid !== 'string' || !UUID_PATTERN.test(order.uuid)) {
      throw new Error('Trade order UUID is invalid')
    }

    contexts.push({
      storeUuid,
      tradeOrderUuid: order.uuid,
      orderId: order.id,
      currency: order.currency
    })
  }
  return contexts
}

export function storeOrderRefundHistoryPath(storeUuid, tradeOrderUuid) {
  return orderRefundHistoryPath(storeUuid, tradeOrderUuid)
}

export function normalizeStoreOrderRefundHistoryResponse(payload, context) {
  if (!payload || !Array.isArray(payload.data)) throw new Error('Unexpected store refund history response')

  return payload.data.map(record => {
    if (!record || typeof record !== 'object' || Array.isArray(record)) {
      throw new Error('Invalid store refund history record')
    }
    if (record.tradeOrderUuid && record.tradeOrderUuid.toLowerCase() !== context.tradeOrderUuid.toLowerCase()) {
      throw new Error('Store refund history returned a different Trade order')
    }
    if (record.storeUuid && record.storeUuid.toLowerCase() !== context.storeUuid.toLowerCase()) {
      throw new Error('Store refund history returned a different Store')
    }

    return {
      ...record,
      tradeOrderUuid: context.tradeOrderUuid,
      storeUuid: context.storeUuid,
      orderId: record.orderId ?? context.orderId,
      currency: record.currency || context.currency || 'CNY'
    }
  })
}

export function pageOrderRefundRecords(records, page = 1, limit = 20, status = '') {
  if (!Array.isArray(records)) throw new Error('Unexpected refund record collection')

  const pageSize = Math.min(100, Math.max(1, Number(limit) || 20))
  const filtered = records
    .filter(record => !status || record.status === status)
    .sort((left, right) => {
      const leftTime = Date.parse(left.createdAt)
      const rightTime = Date.parse(right.createdAt)
      if (Number.isFinite(leftTime) && Number.isFinite(rightTime) && leftTime !== rightTime) return rightTime - leftTime
      return String(right.uuid || '').localeCompare(String(left.uuid || ''))
    })

  const total = filtered.length
  const pages = Math.max(1, Math.ceil(total / pageSize))
  const currentPage = Math.min(pages, Math.max(1, Number(page) || 1))
  const offset = (currentPage - 1) * pageSize

  return {
    items: filtered.slice(offset, offset + pageSize),
    total,
    page: currentPage,
    limit: pageSize
  }
}

export function orderDetailPath(tradeOrderUuid) {
  if (typeof tradeOrderUuid !== 'string' || !UUID_PATTERN.test(tradeOrderUuid)) return ''
  return `/order/${tradeOrderUuid}/detail`
}

export function formatRefundRecordDate(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}

export function refundStatusTagType(status) {
  return ({
    succeeded: 'success',
    failed: 'danger',
    unknown: 'warning',
    processing: 'info'
  })[status] || 'info'
}
