import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'
import {
  auditStoreSetup,
  STORE_SETUP_AUDIT_STATUSES
} from '../../business/admin/config/components/store-setup-audit.mjs'

const storeUuid = '5c67fe8c-b31b-4e17-ad0f-739821bb9c14'
const userUuid = '55a5d068-5b27-4b65-b2d2-f090e04aa6f0'
const categoryUuid = '8c91ca99-7598-4b6b-9095-8d36a73c40d1'
const productUuid = 'b28f209a-12aa-4973-864d-c97d0f40ad39'
const deviceUuid = '3894543f-7045-422f-98b7-db8a9cf28aa5'
const templateUuid = 'cc5cc0df-e79f-4ea3-ab28-33409471143a'
const accountUuid = 'f71512b6-482e-4bad-a590-0a15243fe91d'
const merchantUuid = '43b2ceaa-c22f-47df-9664-cbc6c484a561'
const profileUuid = '62acb7e2-2a86-4d5e-a88a-ac1da230d683'
const wechatAppUuid = '77646bc5-51f0-4e83-8551-f0ab4a7458f2'

function configuredData() {
  return {
    members: [{ userUuid, role: 'owner', status: 'active' }],
    assignments: [{ userUuid, scopeType: 'store', scopeUuid: storeUuid }],
    categories: [{
      id: 1,
      uuid: categoryUuid,
      name: 'Drinks',
      enabled: true,
      store: { uuid: storeUuid }
    }],
    products: [{
      id: 1,
      uuid: productUuid,
      name: 'Latte',
      status: 'active',
      isDeleted: false,
      store: { uuid: storeUuid },
      category: { id: 1, uuid: categoryUuid },
      specifications: [{ id: 1, name: 'Regular', price: 2800, status: 'active' }]
    }],
    printerAccounts: [{ uuid: accountUuid, name: 'Printer account', enabled: true }],
    printerDevices: [{
      uuid: deviceUuid,
      name: 'Front printer',
      storeUuid,
      account: { uuid: accountUuid },
      kind: 'receipt',
      enabled: true
    }],
    printerTemplates: [{ uuid: templateUuid, name: 'Receipt', kind: 'receipt', enabled: true }],
    printerRules: [{
      uuid: 'dced9c68-ada6-46dd-b6a2-092650f6a777',
      name: 'Paid receipt',
      storeUuid,
      enabled: true,
      events: ['payment.succeeded'],
      deviceUuid,
      templateUuid,
      copies: 1
    }],
    huifuSettings: [{
      storeUuid,
      merchantUuid,
      enabled: true,
      allowedMethods: ['native', 'jspay'],
      defaultMethod: 'native',
      posEnabled: false
    }],
    huifuGrants: [{ storeUuid, merchantUuid }],
    huifuMerchants: [{
      uuid: merchantUuid,
      name: 'Huifu merchant',
      providerUuid: profileUuid,
      capabilities: ['native', 'jspay'],
      enabled: true
    }],
    huifuProfiles: [{ uuid: profileUuid, name: 'Production', enabled: true }],
    huifuPosDevices: [],
    wechatSettings: [{
      storeUuid,
      miniappUuid: wechatAppUuid,
      officialUuid: null,
      merchantUuid: null
    }],
    wechatApplications: [{ uuid: wechatAppUuid, name: 'Mini Program', type: 'miniapp' }],
    wechatMerchants: []
  }
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

function sourceTranslationKeys() {
  const moduleSource = readFileSync(new URL(
    '../../business/admin/config/components/store-setup-audit.mjs',
    import.meta.url
  ), 'utf8')
  const componentSource = readFileSync(new URL(
    '../../business/admin/config/components/StoreSetupAuditAction.vue',
    import.meta.url
  ), 'utf8')
  const keys = new Set(['Enabled', 'Disabled'])

  for (const match of componentSource.matchAll(/(?:\$t|\bt)\(['"]([^'"]+)['"]/g)) keys.add(match[1])
  for (const match of componentSource.matchAll(/['"](Audit source [^'"]+)['"]/g)) keys.add(match[1])
  for (const match of moduleSource.matchAll(/labelKey:\s*['"]([^'"]+)['"]/g)) keys.add(match[1])
  for (const match of moduleSource.matchAll(/issue\(\s*STORE_SETUP_AUDIT_STATUSES\.[a-z]+,\s*'((?:\\'|[^'])*)'/g)) {
    keys.add(match[1].replace(/\\'/g, "'"))
  }

  return [...keys]
}

test('a fully configured store is reported as configured with manual verification items', () => {
  const result = auditStoreSetup({
    store: {
      uuid: storeUuid,
      code: 'SH001',
      name: 'Shanghai store',
      timezone: 'Asia/Shanghai',
      status: 'active'
    },
    data: configuredData()
  })

  assert.equal(result.overallStatus, STORE_SETUP_AUDIT_STATUSES.manual)
  assert.deepEqual(result.summary, { complete: 3, action: 0, manual: 5, failed: 0 })
  assert.deepEqual(result.steps.map(step => step.status), [
    'complete',
    'complete',
    'manual',
    'complete',
    'manual',
    'manual',
    'manual',
    'manual'
  ])
})

test('missing store setup data is reported as actionable omissions', () => {
  const result = auditStoreSetup({
    store: {
      uuid: storeUuid,
      code: '',
      name: '',
      timezone: 'Asia/Shanghai',
      status: 'suspended'
    }
  })
  const issueKeys = result.steps.flatMap(step => step.issues.map(issue => issue.messageKey))

  assert.equal(result.overallStatus, STORE_SETUP_AUDIT_STATUSES.action)
  assert.equal(result.summary.action, 8)
  assert.ok(issueKeys.includes('Store required fields are missing: {0}'))
  assert.ok(issueKeys.includes('Store is not active'))
  assert.ok(issueKeys.includes('No active store member'))
  assert.ok(issueKeys.includes('No enabled store product category'))
  assert.ok(issueKeys.includes('No enabled printer device for this store'))
  assert.ok(issueKeys.includes('No enabled order print rule for this store'))
  assert.ok(issueKeys.includes('No store Huifu payment setting'))
  assert.ok(issueKeys.includes('No store WeChat setting'))
})

test('failed management queries are not misreported as missing configuration', () => {
  const result = auditStoreSetup({
    store: {
      uuid: storeUuid,
      code: 'SH001',
      name: 'Shanghai store',
      timezone: 'Asia/Shanghai',
      status: 'active'
    },
    failures: { members: 'HTTP 500' }
  })

  assert.equal(result.overallStatus, STORE_SETUP_AUDIT_STATUSES.failed)
  assert.deepEqual(result.steps[1].failures, ['members'])
  assert.deepEqual(result.steps[1].issues, [])
  assert.deepEqual(result.steps[2].failures, ['members'])
})

test('invalid payment relationships identify the exact configuration gaps', () => {
  const data = configuredData()
  data.huifuSettings[0].merchantUuid = '00000000-0000-4000-8000-000000000001'
  data.huifuGrants = []
  const result = auditStoreSetup({
    store: {
      uuid: storeUuid,
      code: 'SH001',
      name: 'Shanghai store',
      timezone: 'Asia/Shanghai',
      status: 'active'
    },
    data
  })
  const payment = result.steps.find(step => step.key === 'configure-payments')
  const issueKeys = payment.issues.map(issue => issue.messageKey)

  assert.equal(payment.status, STORE_SETUP_AUDIT_STATUSES.action)
  assert.ok(issueKeys.includes('The configured Huifu merchant does not exist'))
  assert.ok(issueKeys.includes('The Huifu merchant is not granted to this store'))
})

test('all audit labels and issue messages exist in every business locale', () => {
  const keys = sourceTranslationKeys()
  assert.ok(keys.length > 60)

  for (const locale of ['en', 'zh', 'zh-Hant', 'ja']) {
    const messages = loadMessages(locale)
    for (const key of keys) {
      assert.equal(typeof messages[key], 'string', `${locale}: missing ${key}`)
      assert.ok(messages[key].trim(), `${locale}: empty ${key}`)
    }
  }
})
