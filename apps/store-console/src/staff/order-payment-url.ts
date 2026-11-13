import { DEFAULT_CUSTOMER_ORDERING_BASE_URL } from './table-qr'

export const DEFAULT_CUSTOMER_PAYMENT_BASE_URL = DEFAULT_CUSTOMER_ORDERING_BASE_URL

const configuredPaymentBaseUrl = import.meta.env.VITE_CUSTOMER_PAYMENT_BASE_URL
  || import.meta.env.VITE_CUSTOMER_ORDERING_BASE_URL
  || DEFAULT_CUSTOMER_PAYMENT_BASE_URL

export function customerPaymentUrl(orderUuid: string, baseUrl = configuredPaymentBaseUrl): string {
  const orderId = orderUuid.trim()
  if (!orderId) throw new Error('orderUuid is required')
  const base = new URL(`${baseUrl.replace(/\/+$/, '')}/`)
  if (base.protocol !== 'https:' && base.protocol !== 'http:') throw new Error('invalid payment base URL')
  const url = new URL('h5/p.html', base)
  url.searchParams.set('uuid', orderId)
  return url.toString()
}
