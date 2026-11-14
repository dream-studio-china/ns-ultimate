import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import en from '../../business/admin/i18n/en.js'
import zh from '../../business/admin/i18n/zh.js'
import zhHant from '../../business/admin/i18n/zh-Hant.js'
import ja from '../../business/admin/i18n/ja.js'

function translationHarness(locale = 'zh') {
  const source = readFileSync(new URL('./i18n.js', import.meta.url), 'utf8')
    .replace(/^import .*\n/gm, '')
    .replaceAll('export function ', 'function ')
    .replace('export default', 'globalThis.plugin =')
  const fallbacks = []
  const context = {
    en, zh, zhHant, ja,
    coreI18n: { install(app) { app.config.globalProperties.$coreInstalled = true } },
    coreTranslate: (key, ...args) => { fallbacks.push([key, ...args]); return 'core result' },
    getCoreLocale: () => locale,
    setCoreLocale: value => { locale = value }
  }
  runInNewContext(`${source}\nglobalThis.translate = t; globalThis.changeLocale = setLocale`, context)
  return { t: context.translate, setLocale: context.changeLocale, plugin: context.plugin, fallbacks }
}

test('simulation completion interpolates named counts including zero', () => {
  const { t } = translationHarness()
  assert.equal(t('Simulation completed: {passed} passed, {failed} failed, {errors} errors.', { passed: 12, failed: 0, errors: 3 }), '模拟完成：通过 12，未通过 0，错误 3。')
  assert.equal(t('Gifts: {count}', { count: 0 }), '赠品数量：0')
})

for (const [locale, messages] of Object.entries({ en, zh, 'zh-Hant': zhHant, ja })) {
  test(`${locale} interpolates every named business message without leaving placeholders`, () => {
    const { t } = translationHarness(locale)
    let checked = 0
    for (const [key, message] of Object.entries(messages)) {
      const names = [...new Set([...message.matchAll(/\{([A-Za-z_][A-Za-z0-9_]*)\}/g)].map(match => match[1]))]
      if (!names.length) continue
      const params = Object.fromEntries(names.map((name, index) => [name, index === 0 ? 0 : `value-${name}`]))
      let expected = message
      for (const name of names) expected = expected.split(`{${name}}`).join(String(params[name]))
      assert.equal(t(key, params), expected, key)
      checked++
    }
    assert.equal(checked, 7)
  })
}

test('positional interpolation stays compatible with existing business callers', () => {
  const { t } = translationHarness('en')
  assert.equal(t('Confirm product copy from {0} to {1}', 'Source', 'Target'), 'Copy products from “Source” to “Target”? This writes real store data.')
  assert.equal(t('Copying product {0} of {1}', 0, false), 'Copying product 0 of false…')
  assert.equal(t('Copying product {0} of {1}', 1), 'Copying product 1 of …')
  assert.equal(t('Copying product {0} of {1}'), 'Copying product {0} of {1}…')
})

test('named values are literal, own properties only, and missing values remain visible', () => {
  const { t } = translationHarness('en')
  assert.equal(t('Gifts: {count}', { count: false }), 'Gifts: false')
  assert.equal(t('Gifts: {count}', { count: '$& {count}' }), 'Gifts: $& {count}')
  assert.equal(t('Gifts: {count}', Object.create({ count: 42 })), 'Gifts: {count}')
  for (const value of [undefined, null, {}, { count: null }, []]) assert.equal(t('Gifts: {count}', value), 'Gifts: {count}')
})

test('plugin installs the actual business translator and preserves core fallback and locale switching', () => {
  const { t, setLocale, plugin, fallbacks } = translationHarness()
  const app = { config: { globalProperties: {} } }
  plugin.install(app)
  assert.equal(app.config.globalProperties.$coreInstalled, true)
  assert.equal(app.config.globalProperties.$t, t)
  assert.equal(app.config.globalProperties.$t('Gifts: {count}', { count: 2 }), '赠品数量：2')
  setLocale('en')
  assert.equal(app.config.globalProperties.$t('Gifts: {count}', { count: 2 }), 'Gifts: 2')
  assert.equal(t('Core-only label', 0, 'value'), 'core result')
  assert.deepEqual(fallbacks, [['Core-only label', 0, 'value']])
})
