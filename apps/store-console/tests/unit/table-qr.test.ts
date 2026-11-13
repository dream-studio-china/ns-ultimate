import { describe, expect, it } from 'vitest'
import { customerPaymentUrl, DEFAULT_CUSTOMER_PAYMENT_BASE_URL } from '../../src/staff/order-payment-url'
import { counterQrFileNames, customerOrderingUrl, DEFAULT_CUSTOMER_ORDERING_BASE_URL } from '../../src/staff/table-qr'

describe('table ordering QR helpers', () => {
  it('uses the server ordering domain and includes the counter id', () => {
    const url = customerOrderingUrl('00000000-0000-4000-8000-000000000006', '00000000-0000-4000-8000-000000000001', DEFAULT_CUSTOMER_ORDERING_BASE_URL)
    expect(url).toBe('https://ns-ultimate.wscoffee.com.cn/h5/order/index.html?counterId=00000000-0000-4000-8000-000000000006&storeUuid=00000000-0000-4000-8000-000000000001')
  })

  it('supports a configured base path', () => {
    const url = customerOrderingUrl('counter id', 'store id', 'https://example.com/app')
    expect(url).toBe('https://example.com/app/h5/order/index.html?counterId=counter+id&storeUuid=store+id')
  })

  it('names PNG files from table names and disambiguates duplicate names', () => {
    const names = counterQrFileNames([
      { uuid: 'a', name: 'A/01', code: 'A01' },
      { uuid: 'b', name: '大厅桌', code: 'B02' },
      { uuid: 'c', name: '大厅桌', code: 'C03' },
    ])
    expect(names).toEqual(['A_01.png', '大厅桌-B02.png', '大厅桌-C03.png'])
  })

  it('builds the customer payment entry URL with only the Trade order UUID', () => {
    const url = customerPaymentUrl('order-uuid', DEFAULT_CUSTOMER_PAYMENT_BASE_URL)
    expect(url).toBe('https://ns-ultimate.wscoffee.com.cn/h5/payment/p.html?o=order-uuid')
  })
})
