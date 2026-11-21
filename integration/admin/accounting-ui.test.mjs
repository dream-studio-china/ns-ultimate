import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { createRequire } from 'node:module'
import { test } from 'node:test'
import { runInNewContext } from 'node:vm'
import { mergeRoutes } from './merge-config.mjs'

const require = createRequire(new URL('../../core/crud-admin/package.json', import.meta.url))
const { compileScript, compileTemplate, parse } = require('@vue/compiler-sfc')
const finance = new URL('../../business/admin/accounting/', import.meta.url)
const read = name => readFileSync(new URL(name, finance), 'utf8')
const routeSource = source => source.replace(/^import .*$/gm, '').replace('export default', 'routes =')

test('Finance navigation exposes separate pages without changing the original entry URL', () => {
  const core = { r: entity => [{ path: `/dummy/${entity}/list`, name: `${entity}List` }], t: x => x, Layout: {}, routes: null }
  runInNewContext(routeSource(read('../../../core/crud-admin/src/configs/routes.js')), core)
  const business = { ...core, coreRoutes: core.routes, routes: null, OrderRefundRecords: {} }
  runInNewContext(routeSource(read('../router.js')), business)
  const route = mergeRoutes(core.routes, business.routes).find(route => route.path === '/finance')
  assert.equal(route.redirect, '/finance/ledger')
  assert.deepEqual(Array.from(route.children.filter(child => !child.hidden), child => child.path), ['ledger', 'accounts', 'periods', 'journals', 'reports', 'audit'])
  assert.ok(route.children.filter(child => !child.hidden).every(child => child.meta.icon === 'el-icon-caret-right'))
  assert.ok(route.children.filter(child => child.hidden).every(child => child.meta.activeMenu === '/finance/journals'))
  for (const [name, action] of [['AccountingJournalCreate', 'new'], ['AccountingJournalEdit', 'edit'], ['AccountingJournalDetail', 'detail']]) {
    const target = route.children.find(child => child.name === name).redirect({ params: { id: 'example' }, query: { origin: 'opening' } })
    assert.equal(target.name, 'AccountingJournals')
    assert.equal(target.query.journalAction, action)
    assert.equal(target.query.journal, action === 'new' ? undefined : 'example')
    assert.equal(target.query.origin, 'opening')
  }
})

test('Finance views use native EasyAdmin create dialogs where resource endpoints are available', () => {
  for (const filename of readdirSync(finance).filter(name => name.endsWith('.vue'))) {
    const { descriptor, errors } = parse(read(filename), { filename })
    assert.deepEqual(errors, [], filename)
    const script = compileScript(descriptor, { id: filename })
    const output = compileTemplate({ source: descriptor.template.content, filename, id: filename, compilerOptions: { bindingMetadata: script.bindings } })
    assert.deepEqual(output.errors, [], filename)
    assert.doesNotMatch(descriptor.template.content, /<(?:input|table|form)(?:\s|>)/)
  }
  assert.match(read('FinanceList.vue'), /ui\/vue\/ListAdmin\.vue/)
  assert.match(read('FinanceList.vue'), /'new', 'detail', 'edit', 'delete', 'batch_edit', 'batch_delete'/)
  assert.match(read('AccountingJournalDetail.vue'), /ui\/vue\/DetailAdmin\.vue/)
  assert.match(read('AccountingJournalDetail.vue'), /:editable="false"/)
  assert.match(read('FinanceCommandForm.vue'), /ui\/vue\/FormAdmin\.vue/)
  assert.match(read('FinanceCommandForm.vue'), /\bembedded\b/)
  for (const page of ['AccountingWorkspace.vue', 'FinanceCommandDialog.vue']) {
    assert.match(read(page), /<finance-command-form/)
    assert.doesNotMatch(read(page), /<el-form(?:\s|>)/)
  }
  for (const page of ['AccountingAccounts.vue', 'AccountingPeriods.vue']) {
    assert.match(read(page), /<list-admin/)
    assert.match(read(page), /:config="config"/)
    assert.match(read(page), /prefix: resourcePrefix/)
    assert.doesNotMatch(read(page), /<el-dialog|<finance-command-form|#topButton/)
  }
  assert.match(read('use-finance.js'), /\/api\/v1\/manage\/accounting/)
  assert.match(read('use-finance.js'), /\/commands\/\$\{action\}/)
})

test('journal create, edit, detail and confirmation share a centered, scrollable modal', () => {
  assert.match(read('AccountingJournals.vue'), /<finance-modal[\s\S]*<accounting-journal-detail[\s\S]*<accounting-journal-editor/)
  assert.match(read('AccountingJournals.vue'), /const modal = ref\(null\)/)
  assert.doesNotMatch(read('AccountingJournals.vue'), /useRouter|router\.(?:push|replace)/)
  assert.match(read('AccountingWorkspace.vue'), /<accounting-journal-editor origin="opening"/)
  assert.doesNotMatch(read('AccountingWorkspace.vue'), /useRouter|router\.(?:push|replace)/)
  assert.match(read('FinanceCommandDialog.vue'), /<finance-modal/)
  assert.match(read('FinanceModal.vue'), /\balign-center\b/)
  assert.match(read('FinanceModal.vue'), /overflow:\s*auto/)
})

test('finance pages share filter and feedback layouts without nesting route containers', () => {
  for (const page of ['AccountingJournals.vue', 'AccountingReports.vue']) {
    assert.match(read(page), /<finance-filters/)
  }
  for (const dialog of ['AccountingJournalDetail.vue', 'AccountingJournalEditor.vue']) {
    assert.doesNotMatch(read(dialog), /<finance-page/)
  }
  assert.match(read('FinanceModal.vue'), /<finance-feedback/)
  assert.match(read('FinancePage.vue'), /<finance-feedback/)
  assert.match(read('AccountingAudit.vue'), /<finance-modal/)
  assert.match(read('FinanceList.vue'), /sortable: false/)
})
