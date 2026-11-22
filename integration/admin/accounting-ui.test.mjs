import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { createRequire } from 'node:module'
import { test } from 'node:test'
import { runInNewContext } from 'node:vm'
import { mergeRoutes } from './merge-config.mjs'

const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
const { compileScript, compileTemplate, parse } = require('@vue/compiler-sfc')
const { createMemoryHistory, createRouter } = require('vue-router')
const Ajv = require('ajv')
const schema = require('./src/configs/schema/entity-config.schema.json')

const accounting = new URL('../../business/admin/accounting/', import.meta.url)
const collections = new URL('../../business/admin/config/collections/accounting/', import.meta.url)
const read = (name, base = accounting) => readFileSync(new URL(name, base), 'utf8')
const moduleSource = source => source.replace(/^import .*$/gm, '').replace('export default', 'globalThis.exported =')
const routeSource = source => source.replace(/^import .*$/gm, '').replace('export default', 'routes =')
const FINANCE_API = '/api/v1/finance/accounting'

function businessRoutes() {
  const core = { r: entity => [{ path: `/dummy/${entity}/list`, name: `${entity}List` }], t: x => x, Layout: {}, routes: null }
  runInNewContext(routeSource(read('../../../core/crud-admin/src/configs/routes.js')), core)
  const business = { ...core, coreRoutes: core.routes, routes: null, OrderRefundRecords: {} }
  runInNewContext(routeSource(read('../router.js')), business)
  return mergeRoutes(core.routes, business.routes)
}

// Evaluate the real collection configs with the Finance helpers stubbed by
// markers, so their declarations can be inspected and schema-validated.
function stubLedger() {
  const marker = (kind, extra = {}) => ({ kind, render() {}, ...extra })
  const finance = { book: null, can: () => false }
  return {
    finance,
    FINANCE_API,
    sides: ['debit', 'credit'],
    classes: ['asset', 'liability', 'equity', 'revenue', 'expense'],
    resource: (name, plural) => ({ name, prefix: FINANCE_API, plural }),
    can: capability => () => finance.can(capability),
    auditOnlyOnUpdate: () => false,
    required: message => [{ required: true, whitespace: true, message }],
    options: (values, translate) => values.map(value => ({ value, label: translate(value) })),
    financeList: options => Object.assign(() => {}, { options: options ?? {} }),
    textFilter: label => ({ expression: '', type: 'input', label, default: null }),
    choiceFilter: (label, values) => ({ expression: '', type: 'select', label, default: null, data: values.map(value => ({ value, label: value })) }),
    accountFilter: label => Object.assign(() => Promise.resolve({}), { label }),
    dateFilter: label => Object.assign(() => Promise.resolve({}), { label }),
    openingDate: () => null,
    bookToday: () => null,
    ledgerCommand: spec => marker('command', { spec }),
    ledgerLink: spec => marker('link', { spec }),
    recordDialog: spec => marker('dialog', { spec }),
    ledgerOperations: (...actions) => marker('operations', { actions }),
    reportSummary: kind => marker('report-summary', { report: kind }),
    moneyCell: marker('money'),
    labelCell: marker('label'),
    stateCell: marker('state'),
    journalLinkCell: marker('journal-link'),
    IsoDateField: marker('iso-date'),
    JournalLinesField: marker('journal-lines'),
    JournalAttachmentsField: marker('journal-attachments')
  }
}

function loadConfigs() {
  const configs = {}
  for (const file of readdirSync(collections).filter(name => name.endsWith('.js'))) {
    const context = { t: key => key, ledger: stubLedger(), globalThis: {} }
    runInNewContext(moduleSource(read(file, collections)), context)
    Object.assign(configs, context.globalThis.exported)
  }
  return configs
}

function project(value) {
  if (typeof value === 'function') return { $fn: true }
  if (value && typeof value === 'object' && value.render) return { $component: true }
  if (Array.isArray(value)) return value.map(project)
  if (value instanceof RegExp) return { $regexp: true }
  if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, project(item)]))
  return value
}

// Values created in the VM context have another realm's prototypes.
const plain = value => JSON.parse(JSON.stringify(value))
const property = field => typeof field === 'string' ? field : field.property
const fieldOf = (fields, name) => fields.find(field => property(field) === name)

test('Accounting navigation keeps every /finance/accounting link and names the EasyAdmin routes', async() => {
  const routes = businessRoutes()
  assert.ok(!routes.some(route => route.path === '/finance'))
  const finance = routes.find(route => route.path === '/finance/accounting')
  assert.equal(finance.redirect, '/finance/accounting/ledger')
  assert.deepEqual(plain(finance.children.filter(child => !child.hidden).map(child => child.path)),
    ['ledger', 'accounts', 'periods', 'journals', 'reports/trial', 'reports/balances', 'reports/ledger', 'audit'])
  for (const child of finance.children.filter(child => child.component)) {
    assert.equal(child.component.toString().includes('./accounting/FinanceResource.vue'), true, child.name)
  }

  // Navigation resolves lazy components; the matcher only needs the records.
  const records = { ...finance, component: {}, children: finance.children.map(child => child.component ? { ...child, component: {} } : child) }
  const router = createRouter({ history: createMemoryHistory(), routes: [records] })
  const id = '0f0e8a8e-2f7b-4b7a-9b1f-0d7c6b1e2a3c'
  const list = { entity: 'JournalEntry', view: 'list' }
  const expectations = {
    '/finance/accounting/ledger': ['AccountingBookList', { entity: 'AccountingBook', view: 'list' }],
    '/finance/accounting/accounts': ['LedgerAccountList', { entity: 'LedgerAccount', view: 'list' }],
    '/finance/accounting/accounts/CASH': ['LedgerAccountDetail', { entity: 'LedgerAccount', view: 'detail' }],
    '/finance/accounting/periods': ['AccountingPeriodList', { entity: 'AccountingPeriod', view: 'list' }],
    '/finance/accounting/journals': ['JournalEntryList', list],
    // Journal record links show the register with the record dialog open.
    '/finance/accounting/journals/new': ['JournalEntryCreate', { ...list, open: 'create', prefill: { origin: '' } }],
    '/finance/accounting/journals/new/opening': ['JournalEntryCreate', { ...list, open: 'create', prefill: { origin: 'opening' } }],
    [`/finance/accounting/journals/${id}`]: ['JournalEntryDetail', { ...list, open: 'detail', prefill: { id } }],
    [`/finance/accounting/journals/${id}/edit`]: ['JournalEntryUpdate', { ...list, open: 'update', prefill: { id } }],
    '/finance/accounting/reports/trial': ['AccountingTrialBalanceList', { entity: 'AccountingTrialBalance', view: 'list' }],
    '/finance/accounting/audit': ['AccountingAuditRecordList', { entity: 'AccountingAuditRecord', view: 'list' }],
    [`/finance/accounting/audit/${id}`]: ['AccountingAuditRecordDetail', { entity: 'AccountingAuditRecord', view: 'detail' }]
  }
  for (const [path, [name, props]] of Object.entries(expectations)) {
    const resolved = router.resolve(path)
    assert.equal(resolved.name, name, path)
    const routeProps = resolved.matched.at(-1).props.default
    assert.deepEqual(plain(typeof routeProps === 'function' ? routeProps(resolved) : routeProps), props, path)
  }
  await router.push('/finance/accounting/reports')
  assert.equal(router.currentRoute.value.name, 'AccountingTrialBalanceList')
  // Old register links that opened journal dialogs now open the routed pages.
  for (const [query, name] of [
    [{ journalAction: 'detail', journal: id }, 'JournalEntryDetail'],
    [{ journalAction: 'edit', journal: id }, 'JournalEntryUpdate'],
    [{ journalAction: 'new', origin: 'opening' }, 'JournalEntryCreate']
  ]) {
    await router.push({ path: '/finance/accounting/journals', query })
    assert.equal(router.currentRoute.value.name, name)
  }
  assert.equal(router.currentRoute.value.params.origin, 'opening')
  // The register's ListAdmin would read a query key as a filter, so the
  // opening origin moves from the query into the path.
  await router.push('/finance/accounting/ledger')
  await router.push('/finance/accounting/journals/new?origin=opening')
  assert.equal(router.currentRoute.value.fullPath, '/finance/accounting/journals/new/opening')
})

test('Accounting collection configs are valid EasyAdmin entity configs', () => {
  const configs = loadConfigs()
  assert.deepEqual(Object.keys(configs).sort(), [
    'AccountingAuditRecord', 'AccountingBalanceReport', 'AccountingBook', 'AccountingLedgerReport',
    'AccountingPeriod', 'AccountingTrialBalance', 'JournalEntry', 'LedgerAccount'
  ])
  const validate = new Ajv({ allErrors: true, strict: false }).compile(schema)
  for (const [name, config] of Object.entries(configs)) {
    assert.ok(validate(project(config)), `${name}: ${JSON.stringify(validate.errors)}`)
    assert.equal(config.entity.prefix, FINANCE_API, name)
    assert.ok(Array.isArray(config.form?.fields), `${name} needs form.fields for ListAdmin`)
    assert.equal(typeof config.list.data_processor, 'function', `${name} must use the whitelisted Finance list processor`)
    for (const action of ['delete', 'batch_edit', 'batch_delete']) {
      assert.ok(config.list.disabled_actions.includes(action), `${name} must not expose ${action}`)
    }
    for (const filter of Object.values(config.list.list_filter ?? {})) {
      if (typeof filter === 'object') assert.equal(filter.expression, '', `${name} filters must not build @filter expressions`)
    }
  }
})

test('journal resource declares fields, filters, and explicit ledger commands in config', () => {
  const { JournalEntry: journal } = loadConfigs()
  assert.deepEqual(plain(journal.list.data_processor.options), { filters: ['from', 'to', 'state', 'account'] })
  assert.deepEqual(plain(Object.keys(journal.list.list_filter)), ['from', 'to', 'state', 'account'])
  for (const action of ['new', 'detail', 'edit']) assert.ok(journal.list.disabled_actions.includes(action), action)
  const commands = journal.list.actions.map(action => [action.position, action.component.spec.mode ?? action.component.spec.action])
  assert.deepEqual(plain(commands), [['top', 'create'], ['list', 'detail'], ['list', 'update'], ['list', 'post'], ['list', 'void-draft']])
  assert.ok(journal.list.actions.slice(0, 3).every(action => action.component.kind === 'dialog' && action.component.spec.entity === 'JournalEntry'))
  const operations = fieldOf(journal.detail.detail_display, 'operations').component.actions.map(action => action.spec.mode ?? action.spec.action)
  assert.deepEqual(plain(operations), ['update', 'post', 'void-draft', 'reverse'])
  const reverse = fieldOf(journal.detail.detail_display, 'operations').component.actions.at(-1).spec
  assert.deepEqual(plain(reverse.fields.map(property)), ['accounting_date', 'reason'])
  assert.deepEqual(plain(reverse.navigate({ id: 'new-id' })), { name: 'JournalEntryDetail', params: { id: 'new-id' } })

  const form = journal.form.fields
  assert.deepEqual(plain(form.map(property)), ['origin', 'accounting_date', 'summary', 'evidence', 'attachments', 'metadata', 'lines', 'revision'])
  assert.equal(fieldOf(form, 'metadata').type, 'json', 'metadata uses the built-in JSON field')
  assert.equal(fieldOf(form, 'lines').component.kind, 'journal-lines')
  assert.equal(fieldOf(form, 'attachments').component.kind, 'journal-attachments')
  assert.equal(fieldOf(form, 'origin').hidden, 'update')
  assert.equal(fieldOf(form, 'revision').hidden, 'create')
  assert.deepEqual(plain(journal.adapters.finance.fields), { evidence: 'audit', attachments: 'audit', metadata: 'audit' })
  assert.equal(journal.adapters.finance.commandId, true)
})

test('reports reuse the list renderer with a snapshot summary and export', () => {
  const configs = loadConfigs()
  for (const [name, kind, filters] of [
    ['AccountingTrialBalance', 'trial', ['from', 'to']],
    ['AccountingBalanceReport', 'balances', ['from', 'to', 'account']],
    ['AccountingLedgerReport', 'ledger', ['from', 'to', 'account']]
  ]) {
    const config = configs[name]
    assert.equal(config.entity.plural, `reports/${kind}`)
    assert.deepEqual(plain(config.list.data_processor.options), { filters, report: kind })
    assert.deepEqual(plain(Object.keys(config.list.list_filter)), filters)
    assert.deepEqual(plain(config.list.actions.map(action => [action.position, action.component.report])), [['top', kind]])
    assert.ok(config.list.disabled_actions.includes('new') && config.list.disabled_actions.includes('detail'))
  }
})

test('Accounting has no hand-written pages: only the adapter, dialogs, and field plugins', () => {
  const allowed = [
    'FinanceCommandDialog.vue', 'FinanceFeedback.vue', 'FinanceModal.vue', 'FinanceRecordDialog.vue', 'FinanceResource.vue',
    'IsoDateField.vue', 'JournalAttachmentsField.vue', 'JournalLinesField.vue'
  ]
  const files = readdirSync(accounting).filter(name => name.endsWith('.vue'))
  assert.deepEqual(files.sort(), allowed)
  for (const filename of files) {
    const { descriptor, errors } = parse(read(filename), { filename })
    assert.deepEqual(errors, [], filename)
    const script = compileScript(descriptor, { id: filename })
    const output = compileTemplate({ source: descriptor.template.content, filename, id: filename, compilerOptions: { bindingMetadata: script.bindings } })
    assert.deepEqual(output.errors, [], filename)
    if (filename !== 'JournalAttachmentsField.vue') assert.doesNotMatch(descriptor.template.content, /<el-upload/, filename)
  }
  const adapter = read('FinanceResource.vue')
  assert.match(adapter, /<list-admin/)
  assert.match(adapter, /<detail-admin/)
  assert.match(adapter, /<form-admin/)
  assert.doesNotMatch(adapter, /<el-(?:table|form|input|select|date-picker)\b/)
  assert.match(read('FinanceCommandDialog.vue'), /<form-admin[\s\S]*\bembedded\b/)
})
