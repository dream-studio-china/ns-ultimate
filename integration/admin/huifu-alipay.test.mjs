import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

test('Alipay is an additive admin method with unchanged WeChat defaults and localized setup guidance', () => {
  const source = readFileSync(new URL('../../business/admin/config/collections/huifu/StoreHuifuSetting.js', import.meta.url), 'utf8')
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.config =')
  const context = { t: key => key, orderByIdDesc: {} }
  vm.runInNewContext(source, context)
  const fields = context.config.StoreHuifuSetting.form.fields
  const defaultMethod = fields.find(field => field.property === 'defaultMethod')
  assert.equal(defaultMethod.default_value, 'jspay')
  assert.equal(JSON.stringify(fields.find(field => field.property === 'allowedMethods').default_value), '["jspay"]')
  assert.ok(defaultMethod.type_options.options.some(option => option.value === 'alipay' && option.label === 'Alipay QR payment'))
  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const messages = readFileSync(new URL(`../../business/admin/i18n/${locale}.js`, import.meta.url), 'utf8')
      .replace(/^import .*$/gm, '').replace('export default', 'globalThis.messages =')
    const scope = { designerMessages: () => ({}) }
    vm.runInNewContext(messages, scope)
    assert.ok(scope.messages['Alipay QR payment'])
    assert.ok(scope.messages['Huifu capabilities help'].includes('alipay'))
    assert.ok(scope.messages['Huifu allowed methods help'].includes('alipay'))
  }
})
