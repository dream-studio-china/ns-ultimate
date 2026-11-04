import assert from 'node:assert/strict'
import test from 'node:test'
import en from '../../business/admin/i18n/en.js'
import zh from '../../business/admin/i18n/zh.js'
import zhHant from '../../business/admin/i18n/zh-Hant.js'
import ja from '../../business/admin/i18n/ja.js'

const labels = {
  account: ['Account', '账号', '帳號', 'アカウント'],
  definition: ['Definition', '定义', '定義', '定義'],
  device: ['Device', '设备', '裝置', '端末'],
  copies: ['Copies', '份数', '份數', '部数'],
  Copies: ['Copies', '份数', '份數', '部数'],
  Channel: ['Channel', '渠道', '渠道', 'チャネル'],
  Online: ['Online', '在线', '在線', 'オンライン'],
  Required: ['Required', '必需', '必需', '必須'],
}

for (const [index, [locale, messages]] of Object.entries({ en, zh, 'zh-Hant': zhHant, ja }).entries()) {
  test(`${locale} keeps shared labels independent of printer and payment screens`, () => {
    for (const [key, translations] of Object.entries(labels)) {
      assert.equal(messages[key], translations[index], key)
    }
  })

  test(`${locale} retains explicit printer labels instead of narrowing generic keys`, () => {
    assert.notEqual(messages.account, messages['Printer account'])
    assert.notEqual(messages.device, messages['Printer device'])
    assert.equal(typeof messages['Printer provider'], 'string')
    assert.equal(typeof messages['Payment capabilities'], 'string')
  })
}
