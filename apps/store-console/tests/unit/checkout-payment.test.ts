import { describe, expect, it } from 'vitest'
import { checkoutPaymentMethods } from '../../src/staff/checkout-payment'

describe('checkout payment configuration', () => {
  it('reads enabled channel maps from a store payment setting', () => {
    expect(checkoutPaymentMethods({
      paymentSetting: {
        channels: {
          wechat: { enabled: true, name: '微信支付' },
          alipay: { enabled: false },
          cash: true,
        },
      },
    })).toEqual([
      { code: 'wechat', name: '微信支付' },
      { code: 'cash', name: 'Cash' },
    ])
  })

  it('reads method arrays from store settings without inventing a provider', () => {
    expect(checkoutPaymentMethods({
      settings: { paymentMethods: ['huifu', { code: 'card', name: 'UnionPay' }] },
    })).toEqual([
      { code: 'huifu', name: 'Huifu Pay' },
      { code: 'card', name: 'UnionPay' },
    ])
    expect(checkoutPaymentMethods({ settings: {} })).toEqual([])
  })
})
