// Mounts the Accounting collection configs through the Finance adapter with
// the real ListAdmin, FormAdmin, and DetailAdmin, against an in-memory API.
import { flushPromises, mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import { h } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'

const api = vi.hoisted(() => {
  const journalId = '0f0e8a8e-2f7b-4b7a-9b1f-0d7c6b1e2a3c'
  const cashId = 'ab7e622b-157d-457b-a81e-f4124e7bb1e7'
  const store = { uuid: 'ab7e622b-157d-457b-a81e-f4124e7bb1e6', name: 'North Shop', code: 'NS01' }
  const state = { calls: [] }
  const initialCashCategories = [
    { id: 'purchase', name: 'Purchase', direction: 'expense' },
    { id: 'transport', name: 'Transport', direction: 'expense' },
    { id: 'maintenance', name: 'Maintenance', direction: 'expense' },
    { id: 'other_expense', name: 'Other expense', direction: 'expense' },
    { id: 'other_income', name: 'Other income', direction: 'income' }
  ]
  let cashCategories = [...initialCashCategories]
  const workspace = {
    book: { id: 1, entity: 'Test', currency: 'CNY', scale: 2, timezone: 'UTC', opening_date: '2026-01-01', opening_state: 'pending' },
    accounts: [
      { code: 'CASH', name: 'Cash', classification: 'asset', normal_side: 'debit', active: 1, postable: 1 },
      { code: 'EQUITY', name: 'Equity', classification: 'equity', normal_side: 'credit', active: 1, postable: 1 }
    ],
    periods: [{ month: '2026-01', state: 'open' }],
    store_uuids: [],
    cash_stores: [{ ...store, currency: 'CNY' }],
    get cash_categories() { return cashCategories },
    get mapped_cash_categories() { return state.mapping ? [state.mapping.category] : [] },
    capabilities: ['read', 'audit', 'draft', 'post', 'reverse', 'configure', 'lock', 'reopen', 'cash_record']
  }
  const detail = {
    id: journalId, number: null, accounting_date: '2026-01-02', summary: 'Stored draft', state: 'draft', origin: 'manual',
    revision: 3, evidence: 'Note', attachments: ['https://files.example.test/uploads/a.pdf'], metadata: { source_id: 'EXT-1' },
    reversal_of: null, reversed_by: null, posted_by: null, posted_at: null,
    lines: [
      { position: 1, account_code: 'CASH', side: 'debit', amount: '9007199254740993', description: '' },
      { position: 2, account_code: 'EQUITY', side: 'credit', amount: '9007199254740993', description: 'Owner' }
    ]
  }
  const paginator = (total, page = 1, limit = 20) => ({ total, page, limit, pages: Math.max(1, Math.ceil(total / limit)), has_previous: page > 1, has_next: page * limit < total })
  const routes = {
    'GET /api/v1/finance/accounting/workspace': () => ({ code: 0, data: workspace }),
    'GET /api/v1/finance/accounting/books': () => ({ code: 0, paginator: paginator(1), data: [workspace.book] }),
    'GET /api/v1/finance/accounting/cash-mappings': () => ({
      code: 0, paginator: paginator(state.mapping ? 1 : 0),
      data: state.mapping ? [{ ...state.mapping, id: state.mapping.category, category_name: 'Transport', debit_account: 'EQUITY - Equity', credit_account: 'CASH - Cash', status: 'cash_mapping_configured' }] : []
    }),
    'GET /api/v1/finance/accounting/cash-categories': () => ({
      code: 0, paginator: paginator(cashCategories.length), data: cashCategories
    }),
    'POST /api/v1/finance/accounting/cash-categories': (_params, body) => {
      const row = { ...body, id: 'c_test001' }
      cashCategories.push(row)
      return { code: 0, data: row }
    },
    'POST /api/v1/finance/accounting/cash-mappings': (_params, body) => {
      state.mapping = body
      return { code: 0, data: { ...body, id: body.category, category_name: 'Transport', debit_account: 'EQUITY - Equity', credit_account: 'CASH - Cash', status: 'cash_mapping_configured' } }
    },
    'GET /api/v1/finance/accounting/cash-mappings/transport': () => ({ code: 0, data: { ...state.mapping, id: 'transport', category_name: 'Transport', debit_account: 'EQUITY - Equity', credit_account: 'CASH - Cash', status: 'cash_mapping_configured' } }),
    'PUT /api/v1/finance/accounting/cash-mappings/transport': (_params, body) => {
      state.mapping = { category: 'transport', ...body }
      return { code: 0, data: { ...state.mapping, id: 'transport', category_name: 'Transport', debit_account: 'EQUITY - Equity', credit_account: 'CASH - Cash', status: 'cash_mapping_configured' } }
    },
    'DELETE /api/v1/finance/accounting/cash-mappings/transport': () => {
      state.mapping = null
      return { code: 0, data: { id: 'transport' } }
    },
    'GET /api/v1/finance/accounting/available-stores': params => ({ code: 0, data: { stores: params.q === 'north' ? [store] : [] } }),
    'GET /api/v1/finance/accounting/cash-entries': () => ({
      code: 0, paginator: paginator(1),
      data: [{
        id: cashId, store_name: store.name, entry_date: '2026-01-02', category: 'transport', category_name: 'Transport', amount: '12000', currency: 'CNY',
        state: state.voided ? 'voided' : 'active', ...(state.voided ? {} : { cash_version: 'a'.repeat(64) }),
        debit_code: state.mapping?.debit_code, credit_code: state.mapping?.credit_code
      }]
    }),
    'POST /api/v1/finance/accounting/commands/bind-store': () => ({ code: 0, data: { store_uuid: store.uuid } }),
    'POST /api/v1/finance/accounting/commands/unbind-store': () => ({ code: 0, data: { id: store.uuid } }),
    'POST /api/v1/finance/accounting/commands/correct-cash-entry': () => ({ code: 0, data: { id: cashId } }),
    'POST /api/v1/finance/accounting/commands/void-cash-entry': () => {
      state.voided = true
      return { code: 0, data: { id: cashId } }
    },
    'POST /api/v1/finance/accounting/cash-entries': () => ({ code: 0, data: { id: cashId } }),
    'POST /api/v1/finance/accounting/commands/save-cash-mapping': (_params, body) => {
      state.mapping = body
      return { code: 0, data: { id: body.category } }
    },
    'POST /api/v1/finance/accounting/commands/draft-from-cash': () => ({ code: 0, data: { id: journalId, revision: 3, existing: false } }),
    'GET /api/v1/finance/accounting/journals': () => ({
      code: 0, paginator: paginator(1),
      data: [{ id: journalId, accounting_date: '2026-01-02', summary: 'Stored draft', origin: 'manual', state: 'draft', revision: 3, number: null, reversal_of: null }]
    }),
    [`GET /api/v1/finance/accounting/journals/${journalId}`]: () => ({ code: 0, data: detail }),
    'POST /api/v1/finance/accounting/journals': () => ({ code: 0, data: { ...detail, id: 'created-id' } }),
    [`PUT /api/v1/finance/accounting/journals/${journalId}`]: () => ({ code: 0, data: { ...detail, revision: 4 } }),
    'POST /api/v1/finance/accounting/commands/post': () => ({ code: 0, data: { id: journalId, number: '2026-1' } }),
    'GET /api/v1/finance/accounting/reports/trial': params => ({
      code: 0, paginator: paginator(40, Number(params.page), Number(params.limit)),
      data: [{ code: 'CASH', name: 'Cash', opening_debit: '0', opening_credit: '0', movement_debit: '9007199254740993', movement_credit: '0', closing_debit: '9007199254740993', closing_credit: '0' }],
      report: { kind: 'trial', cutoff: params.cutoff ?? '5', currency: 'CNY', scale: 2, totals: { closing_debit: '9007199254740993' } }
    })
  }
  const handle = method => async(url, second, third) => {
    const params = method === 'GET' ? second?.params ?? {} : third?.params ?? {}
    const body = method === 'GET' ? undefined : second
    state.calls.push({ method, url, params: { ...params }, body: body && JSON.parse(JSON.stringify(body)) })
    const saved = /^\/api\/v1\/finance\/accounting\/journals\/(created-id)$/.exec(url)
    if (method === 'GET' && saved) return { code: 0, data: { ...detail, id: saved[1] } }
    const route = routes[`${method} ${url}`]
    if (!route) throw new Error(`Unexpected ${method} ${url}`)
    return route(params, body)
  }
  return {
    state, workspace, journalId, cashId, store,
    resetCashCategories: () => { cashCategories = [...initialCashCategories] },
    request: { get: handle('GET'), post: handle('POST'), put: handle('PUT'), delete: handle('DELETE') }
  }
})

vi.mock('@/utils/request', () => ({ __esModule: true, default: api.request }))
vi.mock('@/utils/auth', () => ({ getToken: () => 'token' }))
vi.mock('@/i18n', () => ({ __esModule: true, t: key => key, getLocale: () => 'en', setLocale: () => {}, default: { install() {} } }))
vi.mock('@/configs/entities', () => ({ __esModule: true, default: {} }))
vi.mock('@/router', () => ({ __esModule: true, asyncRoutes: [], constantRoutes: [], lastRoutes: [], resetRouter: () => {}, default: {} }))
vi.mock('jsoneditor', () => ({
  __esModule: true,
  default: class { constructor(_container, options, value) { this.value = value; this.options = options } set(value) { this.value = value } get() { return this.value } destroy() {} }
}))
vi.mock('@/store', () => {
  const entity = name => `NsUltimate\\Business\\Accounting\\Entity\\${name}`
  const names = ['AccountingBook', 'AccountingPeriod', 'AccountingAuditRecord', 'CashCategory', 'CashJournalMapping', 'JournalEntry', 'LedgerAccount', 'StoreCashEntry']
  return {
    __esModule: true,
    default: {
      getters: { token: 'token', roles: ['ROLE_ADMIN'], entity: { entities: names.map(entity), structures: Object.fromEntries(names.map(name => [entity(name), {}])) } },
      dispatch: () => {},
      commit: () => {}
    }
  }
})
// The configs import the Finance helpers, which read '@/config' lazily; the
// entity map is filled in once the configs have loaded (as in the app).
vi.mock('@/config', () => ({ __esModule: true, default: { get entities() { return globalThis.accountingEntities } } }))

const collections = import.meta.glob('../../business/admin/config/collections/accounting/*.js', { eager: true })
globalThis.accountingEntities = Object.assign({}, ...Object.values(collections).map(module => module.default))
const { default: FinanceResource } = await import('../../business/admin/accounting/FinanceResource.vue')
const { finance } = await import('../../business/admin/accounting/finance-context.js')
const { default: JournalLinesField } = await import('../../business/admin/accounting/JournalLinesField.vue')
const { default: AvailableStoreField } = await import('../../business/admin/accounting/AvailableStoreField.vue')

const page = (path, name, entity, view) => ({ path, name, component: FinanceResource, props: { entity, view }, meta: { title: name } })
const dialog = (path, name, open) => ({
  path, name, component: FinanceResource, meta: { title: 'Journals' },
  props: route => ({ entity: 'JournalEntry', view: 'list', open, prefill: { ...route.params } })
})
const routes = [
  page('/finance/accounting/ledger', 'AccountingBookList', 'AccountingBook', 'list'),
  page('/finance/accounting/cash-categories', 'CashCategoryList', 'CashCategory', 'list'),
  page('/finance/accounting/cash-mappings', 'CashJournalMappingList', 'CashJournalMapping', 'list'),
  page('/finance/accounting/cash-entries', 'StoreCashEntryList', 'StoreCashEntry', 'list'),
  page('/finance/accounting/journals', 'JournalEntryList', 'JournalEntry', 'list'),
  dialog('/finance/accounting/journals/new/:origin(opening)?', 'JournalEntryCreate', 'create'),
  dialog('/finance/accounting/journals/:id([0-9a-fA-F-]{36})/edit', 'JournalEntryUpdate', 'update'),
  dialog('/finance/accounting/journals/:id', 'JournalEntryDetail', 'detail'),
  page('/finance/accounting/reports/trial', 'AccountingTrialBalanceList', 'AccountingTrialBalance', 'list')
]

async function open(path) {
  const router = createRouter({ history: createMemoryHistory(), routes })
  await router.push(path)
  await router.isReady()
  const wrapper = mount({ render: () => h(RouterView, null, {
    default: ({ Component, route }) => h(Component, { key: route.path })
  }) }, {
    attachTo: document.body,
    // Real transitions, so dialogs emit `closed` after leaving.
    global: { plugins: [router, ElementPlus], mocks: { $t: key => key }, stubs: { transition: false } }
  })
  await settle()
  return { wrapper, router }
}

async function settle() {
  for (let i = 0; i < 6; i++) {
    await flushPromises()
    await new Promise(resolve => setTimeout(resolve, 0))
  }
}

const calls = (method, url) => api.state.calls.filter(call => call.method === method && call.url === url)
const button = (wrapper, label) => wrapper.findAll('button').find(node => node.text().trim() === label)
// Dialogs are appended to the document body, outside the mounted wrapper.
const bodyButton = label => Array.from(document.body.querySelectorAll('button')).find(node => node.textContent.trim() === label)
const formAdmin = wrapper => wrapper.findComponent({ name: 'FormAdmin' })
const rowButton = label => Array.from(document.body.querySelectorAll('.el-table__body button')).find(node => node.textContent.trim() === label)
const dialogButton = label => Array.from(document.body.querySelectorAll('.record-dialog button')).find(node => node.textContent.trim() === label)
async function fillDraft(wrapper, summary, amounts) {
  formAdmin(wrapper).vm.form.summary = summary
  const lines = wrapper.findComponent(JournalLinesField)
  Object.assign(lines.vm.rows[0], { account_code: 'CASH', amount: amounts[0] })
  Object.assign(lines.vm.rows[1], { account_code: 'EQUITY', amount: amounts[1], description: 'Owner' })
  await settle()
}

beforeEach(() => {
  api.state.calls.length = 0
  api.state.mapping = null
  api.state.voided = false
  api.resetCashCategories()
  document.body.innerHTML = ''
})

describe('Accounting resources render from collection configs', () => {
  it('records a store purchase without creating a journal', async() => {
    const url = '/api/v1/finance/accounting/cash-entries'
    const { wrapper } = await open('/finance/accounting/cash-entries')
    await button(wrapper, 'Record cash entry').trigger('click')
    await settle()
    const form = formAdmin(wrapper).vm.form
    expect(form.store_uuid).toBe(api.store.uuid)
    expect(document.body.textContent).not.toContain('Cash purchase item')
    Object.assign(form, { date: '2026-01-02', category: 'purchase', amount: '120.001', note: 'Beans' })
    await settle()
    expect(document.body.textContent).toContain('Cash purchase item')
    Object.assign(form, { item: 'Beans', quantity: '2.5', unit: 'kg' })
    await settle()
    bodyButton('Confirm').click()
    await settle()
    expect(calls('POST', url)).toHaveLength(0)
    form.amount = '120.00'
    await settle()
    bodyButton('Confirm').click()
    await settle()
    expect(calls('POST', url)[0].body).toEqual({
      store_uuid: api.store.uuid, date: '2026-01-02', category: 'purchase', direction: 'expense',
      amount: '12000', note: 'Beans', metadata: { item: 'Beans', quantity: '2.5', unit: 'kg' },
      command_id: expect.stringMatching(/^[0-9a-f-]{36}$/)
    })
    expect(api.state.calls.filter(call => call.method === 'POST')).toHaveLength(1)
    expect(calls('GET', url)).toHaveLength(2)
    wrapper.unmount()
  })

  it('records other income without requiring purchase details', async() => {
    const url = '/api/v1/finance/accounting/cash-entries'
    const { wrapper } = await open('/finance/accounting/cash-entries')
    await button(wrapper, 'Record cash entry').trigger('click')
    await settle()
    Object.assign(formAdmin(wrapper).vm.form, { date: '2026-01-02', category: 'other_income', amount: '20' })
    await settle()
    bodyButton('Confirm').click()
    await settle()
    expect(calls('POST', url)[0].body).toMatchObject({ direction: 'income', amount: '2000' })
    expect(calls('POST', url)[0].body).not.toHaveProperty('metadata')
    wrapper.unmount()
  })

  it('creates and edits a cash mapping, then generates a draft without posting', async() => {
    const { wrapper, router } = await open('/finance/accounting/cash-mappings')
    expect(wrapper.text()).toContain('Cash journal settings help')
    await wrapper.findAll('button').find(node => node.text().trim().startsWith('New ')).trigger('click')
    await settle()
    const mapping = { category: 'transport', debit_code: 'EQUITY', credit_code: 'CASH' }
    Object.assign(formAdmin(wrapper).vm.form, mapping)
    await settle()
    dialogButton('Save').click()
    await settle()
    expect(calls('POST', '/api/v1/finance/accounting/cash-mappings')[0].body).toEqual(mapping)
    // ListAdmin refreshes after the dialog's leave transition completes.
    await new Promise(resolve => setTimeout(resolve, 400))
    await settle()
    const transport = () => wrapper.findAll('.el-table__body tr').find(row => row.text().toLowerCase().includes('transport'))
    expect(transport().text()).toContain('EQUITY - Equity')
    await button(transport(), 'Edit').trigger('click')
    await settle()
    expect(formAdmin(wrapper).vm.form).toMatchObject({ debit_code: 'EQUITY', credit_code: 'CASH' })
    expect(calls('GET', '/api/v1/finance/accounting/cash-mappings/transport')).toHaveLength(1)
    dialogButton('Save').click()
    await settle()
    expect(calls('PUT', '/api/v1/finance/accounting/cash-mappings/transport')[0].body).toEqual({ debit_code: 'EQUITY', credit_code: 'CASH' })
    await router.push('/finance/accounting/cash-entries?category=transport')
    await settle()
    const [list] = calls('GET', '/api/v1/finance/accounting/cash-entries')
    expect(list.params).toEqual({ category: 'transport', page: '1', limit: '20' })
    expect(wrapper.text()).toContain('120.00 CNY')
    expect(button(wrapper, 'Cash journal settings')).toBeUndefined()
    rowButton('Generate journal draft').click()
    await settle()
    bodyButton('Confirm').click()
    await settle()
    expect(calls('POST', '/api/v1/finance/accounting/commands/draft-from-cash')[0].body).toEqual({ source_uuid: api.cashId })
    expect(calls('POST', '/api/v1/finance/accounting/commands/post')).toHaveLength(0)
    expect(router.currentRoute.value.name).toBe('JournalEntryDetail')
    wrapper.unmount()
  })

  it('creates a user-defined cash category separately from account mapping', async() => {
    const { wrapper } = await open('/finance/accounting/cash-categories')
    expect(wrapper.text()).toContain('Cash categories help')
    await wrapper.findAll('button').find(node => node.text().trim().startsWith('New ')).trigger('click')
    await settle()
    Object.assign(formAdmin(wrapper).vm.form, { name: 'Staff meals', direction: 'expense' })
    await settle()
    dialogButton('Save').click()
    await settle()
    expect(calls('POST', '/api/v1/finance/accounting/cash-categories')[0].body).toEqual({
      name: 'Staff meals', direction: 'expense'
    })
    wrapper.unmount()
  })

  it('prefills a cash correction and submits the source version and reason', async() => {
    const { wrapper } = await open('/finance/accounting/cash-entries')
    rowButton('Correct cash entry').click()
    await settle()
    const form = formAdmin(wrapper).vm.form
    expect(form).toMatchObject({ date: '2026-01-02', category: 'transport', amount: '120.00' })
    Object.assign(form, { amount: '100.12', reason: 'Wrong amount' })
    await settle()
    bodyButton('Confirm').click()
    await settle()
    expect(calls('POST', '/api/v1/finance/accounting/commands/correct-cash-entry')[0].body).toMatchObject({
      id: api.cashId, cash_version: 'a'.repeat(64), amount: '10012', reason: 'Wrong amount'
    })
    wrapper.unmount()
  })

  it('voids a cash entry and removes its follow-up actions', async() => {
    const { wrapper } = await open('/finance/accounting/cash-entries')
    rowButton('Void cash entry').click()
    await settle()
    formAdmin(wrapper).vm.form.reason = 'Duplicate entry'
    await settle()
    bodyButton('Confirm').click()
    await settle()
    const [voided] = calls('POST', '/api/v1/finance/accounting/commands/void-cash-entry')
    expect(voided.body).toMatchObject({ id: api.cashId, reason: 'Duplicate entry' })
    expect(voided.body.command_id).toMatch(/^[0-9a-f-]{36}$/)
    await new Promise(resolve => setTimeout(resolve, 50))
    await settle()
    expect(wrapper.find('.el-table__body').text()).toContain('voided')
    expect(rowButton('Correct cash entry')).toBeUndefined()
    expect(rowButton('Void cash entry')).toBeUndefined()
    wrapper.unmount()
  })

  it('allows a configurator to select a bound store to unbind', async() => {
    const { wrapper } = await open('/finance/accounting/ledger')
    rowButton('Unbind store from book').click()
    await settle()
    Object.assign(formAdmin(wrapper).vm.form, { id: api.store.uuid, reason: 'Wrong book' })
    await settle()
    bodyButton('Confirm').click()
    await settle()
    expect(calls('POST', '/api/v1/finance/accounting/commands/unbind-store')[0].body).toMatchObject({ id: api.store.uuid, reason: 'Wrong book' })
    wrapper.unmount()
  })

  it('lists journals with whitelisted filters and registered row actions', async() => {
    const { wrapper } = await open('/finance/accounting/journals?state=draft')
    const [list] = calls('GET', '/api/v1/finance/accounting/journals')
    expect(list.params).toEqual({ state: 'draft', page: '1', limit: '20' })
    expect(wrapper.text()).toContain('Stored draft')
    for (const label of ['Details', 'Edit draft', 'Post journal', 'Void draft']) expect(rowButton(label), label).toBeTruthy()
    expect(rowButton('Delete')).toBeUndefined()

    rowButton('Post journal').click()
    await settle()
    bodyButton('Confirm').click()
    await settle()
    const [post] = calls('POST', '/api/v1/finance/accounting/commands/post')
    expect(post.body).toMatchObject({ id: api.journalId, revision: 3 })
    expect(post.body.command_id).toMatch(/^[0-9a-f-]{36}$/)
    // A successful command refreshes the register.
    expect(calls('GET', '/api/v1/finance/accounting/journals')).toHaveLength(2)
    wrapper.unmount()
  })

  it('creates a draft in a dialog through POST /journals with exact minor units and a command key', async() => {
    const { wrapper, router } = await open('/finance/accounting/journals')
    expect(document.body.querySelector('.record-dialog')).toBeNull()
    await button(wrapper, 'New journal draft').trigger('click')
    await settle()
    expect(formAdmin(wrapper).vm.form).toMatchObject({ origin: 'manual', accounting_date: '2026-01-01' })
    await fillDraft(wrapper, 'Manual entry', ['1.50', '1.5'])
    dialogButton('Save').click()
    await settle()
    const [create] = calls('POST', '/api/v1/finance/accounting/journals')
    expect(create.body).toEqual({
      origin: 'manual', accounting_date: '2026-01-01', summary: 'Manual entry', store_uuid: '', command_id: expect.stringMatching(/^[0-9a-f-]{36}$/),
      lines: [
        { account_code: 'CASH', side: 'debit', amount: '150', description: '' },
        { account_code: 'EQUITY', side: 'credit', amount: '150', description: 'Owner' }
      ]
    })
    // The dialog then shows the saved draft over the unchanged register URL.
    expect(calls('GET', '/api/v1/finance/accounting/journals/created-id')).toHaveLength(1)
    expect(router.currentRoute.value.name).toBe('JournalEntryList')
    expect(calls('GET', '/api/v1/finance/accounting/journals').length).toBeGreaterThan(1)
    wrapper.unmount()
  })

  it('opens the opening-journal link as a prefilled create dialog', async() => {
    const { wrapper } = await open('/finance/accounting/journals/new/opening')
    expect(calls('GET', '/api/v1/finance/accounting/journals')).toHaveLength(1)
    expect(formAdmin(wrapper).vm.form).toMatchObject({ origin: 'opening', accounting_date: '2026-01-01' })
    await fillDraft(wrapper, 'Opening position', ['2', '2'])
    dialogButton('Save').click()
    await settle()
    const [create] = calls('POST', '/api/v1/finance/accounting/journals')
    expect(create.body).toMatchObject({ origin: 'opening', accounting_date: '2026-01-01', summary: 'Opening position' })
    wrapper.unmount()
  })

  it('rejects invalid draft lines before any request', async() => {
    const { wrapper } = await open('/finance/accounting/journals/new')
    await fillDraft(wrapper, 'Bad amount', ['1.001', '1'])
    dialogButton('Save').click()
    await settle()
    expect(calls('POST', '/api/v1/finance/accounting/journals')).toHaveLength(0)
    // Only the lines field is invalid; the other required fields are filled.
    const invalid = Array.from(document.body.querySelectorAll('.el-form-item.is-error > .el-form-item__label'), node => node.textContent.trim())
    expect(invalid).toEqual(['Journal lines'])
    expect(formAdmin(wrapper).vm.form.lines).toBeNull()
    wrapper.unmount()
  })

  it('revises a draft in a dialog through PUT /journals/{id} with its revision and without its origin', async() => {
    const { wrapper } = await open(`/finance/accounting/journals/${api.journalId}/edit`)
    const lines = wrapper.findComponent(JournalLinesField)
    expect(lines.vm.rows.map(row => row.amount)).toEqual(['90071992547409.93', '90071992547409.93'])
    formAdmin(wrapper).vm.form.summary = 'Revised'
    await settle()
    dialogButton('Save').click()
    await settle()
    const [update] = calls('PUT', `/api/v1/finance/accounting/journals/${api.journalId}`)
    expect(update.body).toEqual({
      accounting_date: '2026-01-02', summary: 'Revised', evidence: 'Note', revision: 3,
      attachments: ['https://files.example.test/uploads/a.pdf'], metadata: { source_id: 'EXT-1' },
      lines: [
        { account_code: 'CASH', side: 'debit', amount: '9007199254740993', description: '' },
        { account_code: 'EQUITY', side: 'credit', amount: '9007199254740993', description: 'Owner' }
      ]
    })
    wrapper.unmount()
  })

  it('shows journal detail in a dialog and edits it in place', async() => {
    const { wrapper, router } = await open(`/finance/accounting/journals/${api.journalId}`)
    const dialogText = () => document.body.querySelector('.record-dialog')?.textContent ?? ''
    expect(dialogText()).toContain('90071992547409.93')
    expect(dialogText()).toContain('a.pdf')
    for (const label of ['Edit draft', 'Post journal', 'Void draft']) expect(dialogButton(label), label).toBeTruthy()
    expect(dialogButton('Reverse journal')).toBeUndefined()

    dialogButton('Edit draft').click()
    await settle()
    expect(document.body.querySelectorAll('.record-dialog')).toHaveLength(1)
    expect(formAdmin(wrapper).exists()).toBe(true)
    dialogButton('Cancel').click()
    await settle()
    expect(dialogButton('Post journal')).toBeTruthy()

    dialogButton('Close').click()
    await settle()
    await new Promise(resolve => setTimeout(resolve, 400))
    await settle()
    expect(router.currentRoute.value.name).toBe('JournalEntryList')
    wrapper.unmount()
  })

  it('offers store book creation and binding while keeping the opening action', async() => {
    const { wrapper, router } = await open('/finance/accounting/ledger')
    expect(calls('GET', '/api/v1/finance/accounting/books')).toHaveLength(1)
    expect(wrapper.text()).toContain('Test')
    expect(wrapper.findAll('button').some(node => node.text().trim().startsWith('New AccountingBookList'))).toBe(true)
    expect(button(wrapper, 'Add store to book')).toBeTruthy()
    await button(wrapper, 'New opening journal').trigger('click')
    await settle()
    expect(router.currentRoute.value).toMatchObject({ name: 'JournalEntryCreate', params: { origin: 'opening' } })
    wrapper.unmount()
  })

  it('hides book creation and binding without configure capability', async() => {
    const capabilities = api.workspace.capabilities
    api.workspace.capabilities = capabilities.filter(capability => capability !== 'configure')
    try {
      await finance.reload()
      const { wrapper } = await open('/finance/accounting/ledger')
      try {
        expect(wrapper.findAll('button').some(node => node.text().trim().startsWith('New AccountingBookList'))).toBe(false)
        expect(button(wrapper, 'Add store to book')).toBeUndefined()
        expect(button(wrapper, 'New opening journal')).toBeTruthy()
      } finally {
        wrapper.unmount()
      }
    } finally {
      api.workspace.capabilities = capabilities
      await finance.reload()
    }
  })

  it('searches available stores and binds the selected store without typing its UUID', async() => {
    const { wrapper } = await open('/finance/accounting/ledger')
    try {
      await button(wrapper, 'Add store to book').trigger('click')
      await settle()
      const select = wrapper.findComponent(AvailableStoreField).findComponent({ name: 'ElSelect' })
      expect(select.props('remote')).toBe(true)
      await select.props('remoteMethod')(' north ')
      await settle()
      expect(calls('GET', '/api/v1/finance/accounting/available-stores').at(-1).params).toEqual({ q: 'north' })
      expect(select.findAllComponents({ name: 'ElOption' }).map(option => option.props('label'))).toEqual(['North Shop (NS01)'])
      select.vm.$emit('update:modelValue', api.store.uuid)
      await settle()
      bodyButton('Confirm').click()
      await settle()
      expect(calls('POST', '/api/v1/finance/accounting/commands/bind-store').map(call => call.body)).toEqual([{ store_uuid: api.store.uuid }])
    } finally {
      wrapper.unmount()
    }
  })

  it('pages a report on one posting cutoff', async() => {
    const { wrapper } = await open('/finance/accounting/reports/trial')
    const [first] = calls('GET', '/api/v1/finance/accounting/reports/trial')
    expect(first.params).toEqual({ from: '2026-01-01', to: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/), page: '1', limit: '20' })
    expect(wrapper.text()).toContain('Posting cutoff: 5')
    wrapper.findComponent({ name: 'ListAdmin' }).vm.handleCurrentChange(2)
    await settle()
    const [, second] = calls('GET', '/api/v1/finance/accounting/reports/trial')
    expect(second.params).toMatchObject({ page: '2', cutoff: '5', from: first.params.from, to: first.params.to })
    wrapper.unmount()
  })
})
