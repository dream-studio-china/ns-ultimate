import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import { STORE_ONBOARDING_STEPS } from '../../business/admin/config/components/store/store-onboarding-guide.mjs'

function loadStoreOverride() {
  const source = readFileSync(new URL(
    '../../business/admin/config/overrides/store/store.js',
    import.meta.url
  ), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace('export default', 'globalThis.config =')
  const context = {
    t: key => key,
    orderByIdDesc: {},
    StoreContactSchema: {},
    StoreAddressSchema: {},
    StoreSettingsSchema: {},
    StoreDiningModesAction: {},
    StoreOnboardingGuideAction: {},
    StoreSetupAuditAction: {}
  }
  runInNewContext(source, context)
  return JSON.parse(JSON.stringify(context.config))
}

function loadMessages(locale) {
  const context = {}
  const source = readFileSync(new URL(
    `../../business/admin/i18n/${locale}.js`,
    import.meta.url
  ), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace(/^\s*\.\.\.designerMessages\([^)]*\),\s*$/gm, '')
    .replace('export default', 'globalThis.messages =')
  runInNewContext(source, context)
  return context.messages
}

test('Store list registers the onboarding guide beside the default list actions', () => {
  const config = loadStoreOverride()
  assert.deepEqual(config.list.actions.map(action => [action.name, action.position]), [
    ['store-onboarding-guide', 'top'],
    ['store-setup-audit', 'top'],
    ['store-dining-modes', 'list']
  ])
})

test('Store onboarding button is ordered to the right of the default New button', () => {
  const source = readFileSync(new URL(
    '../../business/admin/config/components/store/StoreOnboardingGuideAction.vue',
    import.meta.url
  ), 'utf8')
  assert.match(source, /class="store-onboarding-guide-action"/)
  assert.match(source, /\.store-onboarding-guide-action\s*\{[^}]*order:\s*2;/s)
})

test('Store onboarding guide contains the complete ordered setup flow', () => {
  assert.deepEqual(STORE_ONBOARDING_STEPS.map(step => step.key), [
    'create-store',
    'add-members',
    'grant-permissions',
    'configure-products',
    'add-printers',
    'configure-print-rules',
    'configure-payments',
    'configure-wechat'
  ])
})

test('Store onboarding labels exist in every business locale', () => {
  const keys = [
    'Store onboarding guide',
    'Store onboarding order help',
    'Store onboarding permission help',
    ...STORE_ONBOARDING_STEPS.flatMap(step => [step.titleKey, step.descriptionKey])
  ]

  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const messages = loadMessages(locale)
    for (const key of keys) {
      assert.equal(typeof messages[key], 'string', `${locale}: missing ${key}`)
      assert.ok(messages[key].trim(), `${locale}: empty ${key}`)
    }
  }
})
