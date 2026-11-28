import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'
import coreRoutes from '../../core/crud-admin/src/configs/routes'
import OrderRefundRecords from './config/features/order-refund-records/OrderRefundRecords.vue'

const orderRoute = coreRoutes.find(route => route.name === 'OrderManage')
if (!orderRoute) throw new Error('The admin core must expose the OrderManage route.')
const promotionRoute = coreRoutes.find(route => route.name === 'PromotionManage')
if (!promotionRoute) throw new Error('The admin core must expose the PromotionManage route.')
const storeRoute = coreRoutes.find(route => route.name === 'StoreManage')
if (!storeRoute) throw new Error('The admin core must expose the StoreManage route.')

const accountingPath = '/finance/accounting'
const FinanceResource = () => import('./config/features/accounting/FinanceResource.vue')
const financeMenu = title => ({ title: t(title), icon: 'el-icon-caret-right' })
// Each page renders one Accounting collection config through the thin Finance adapter.
const financePage = (path, name, entity, view, title) => ({
  path, name, component: FinanceResource, props: { entity, view }, meta: financeMenu(title)
})
const hiddenPage = (path, name, entity, view, title, menu) => ({
  ...financePage(path, name, entity, view, title), hidden: true, meta: { title: t(title), activeMenu: `${accountingPath}/${menu}` }
})
// Direct record links show the register with that record's dialog open. The
// register's ListAdmin reads every query key as a filter, so link values
// (the opening origin) travel as route params instead.
const dialogPage = (path, name, entity, open, menu, extra = {}) => ({
  path, name, component: FinanceResource, hidden: true,
  props: route => ({ entity, view: 'list', open, prefill: { ...route.params } }),
  meta: { title: t('Journals'), activeMenu: `${accountingPath}/${menu}` },
  ...extra
})
const openingQuery = to => to.query.origin === 'opening' && !to.params.origin
  ? { name: 'JournalEntryCreate', params: { origin: 'opening' }, query: {} }
  : true
const journalId = ':id([0-9a-fA-F-]{36})'
// Older links opened journal dialogs through query parameters on the register.
const legacyJournalLink = to => {
  const { journalAction: action, journal: id, origin, ...query } = to.query
  if (action === 'new') return { name: 'JournalEntryCreate', params: origin === 'opening' ? { origin } : {} }
  if (typeof id === 'string' && id && action === 'edit') return { name: 'JournalEntryUpdate', params: { id } }
  if (typeof id === 'string' && id && action === 'detail') return { name: 'JournalEntryDetail', params: { id } }
  return action === undefined ? true : { name: 'JournalEntryList', query }
}

export default {
  add: [
    {
      path: accountingPath,
      name: 'Finance',
      component: Layout,
      redirect: `${accountingPath}/ledger`,
      meta: { title: t('Finance management'), icon: 'el-icon-money' },
      alwaysShow: true,
      children: [
        financePage('ledger', 'AccountingBookList', 'AccountingBook', 'list', 'Book setup'),
        hiddenPage('ledger/:id(\\d+)', 'AccountingBookDetail', 'AccountingBook', 'detail', 'Book setup', 'ledger'),
        financePage('accounts', 'LedgerAccountList', 'LedgerAccount', 'list', 'Accounts'),
        hiddenPage('accounts/:id/edit', 'LedgerAccountUpdate', 'LedgerAccount', 'form', 'Accounts', 'accounts'),
        hiddenPage('accounts/:id', 'LedgerAccountDetail', 'LedgerAccount', 'detail', 'Accounts', 'accounts'),
        financePage('periods', 'AccountingPeriodList', 'AccountingPeriod', 'list', 'Accounting periods'),
        financePage('cash-entries', 'StoreCashEntryList', 'StoreCashEntry', 'list', 'Store cash entries'),
        financePage('cash-categories', 'CashCategoryList', 'CashCategory', 'list', 'Cash categories'),
        financePage('cash-mappings', 'CashJournalMappingList', 'CashJournalMapping', 'list', 'Cash journal settings'),
        hiddenPage('periods/:id', 'AccountingPeriodDetail', 'AccountingPeriod', 'detail', 'Accounting periods', 'periods'),
        { ...financePage('journals', 'JournalEntryList', 'JournalEntry', 'list', 'Journals'), beforeEnter: legacyJournalLink },
        dialogPage('journals/new/:origin(opening)?', 'JournalEntryCreate', 'JournalEntry', 'create', 'journals', { beforeEnter: openingQuery }),
        dialogPage(`journals/${journalId}/edit`, 'JournalEntryUpdate', 'JournalEntry', 'update', 'journals'),
        dialogPage(`journals/${journalId}`, 'JournalEntryDetail', 'JournalEntry', 'detail', 'journals'),
        // ListAdmin restores a title from any child whose redirect equals the
        // current path, so this redirect route must carry meta.title.
        { path: 'reports', name: 'AccountingReports', hidden: true, redirect: `${accountingPath}/reports/trial`, meta: financeMenu('trial') },
        financePage('reports/trial', 'AccountingTrialBalanceList', 'AccountingTrialBalance', 'list', 'trial'),
        financePage('reports/balances', 'AccountingBalanceReportList', 'AccountingBalanceReport', 'list', 'balances'),
        financePage('reports/ledger', 'AccountingLedgerReportList', 'AccountingLedgerReport', 'list', 'ledger'),
        financePage('audit', 'AccountingAuditRecordList', 'AccountingAuditRecord', 'list', 'Accounting audit'),
        hiddenPage('audit/:id', 'AccountingAuditRecordDetail', 'AccountingAuditRecord', 'detail', 'Accounting audit', 'audit')
      ]
    },
    {
      path: '/settings/appearance',
      hidden: true,
      redirect: '/system-option/appearance'
    },
    {
      path: '/printer',
      name: 'PrinterManage',
      component: Layout,
      meta: { title: t('Printer management'), icon: 'el-icon-printer', roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] },
      children: [
        ...r('PrinterAccount', t('Printer accounts')),
        ...r('PrinterDevice', t('Printer devices')),
        ...r('PrinterTemplate', t('Printer templates')),
        ...r('PrinterOrderRule', t('Order print rules')),
        ...r('PrinterJob', t('Printer jobs'))
      ]
    },
    {
      path: '/business',
      name: 'BusinessManage',
      hidden: true,
      component: Layout,
      meta: {
        title: t('Business'),
        icon: 'el-icon-menu',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('PaymentSetting', t('Payment settings')),
      ]
    },
    {
      path: '/wechat-configuration',
      name: 'WechatConfiguration',
      component: Layout,
      meta: {
        title: t('WeChat configuration'),
        icon: 'wechat',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('WechatApplication', t('WeChat applications')),
        ...r('WechatMerchant', t('WeChat merchants')),
        ...r('StoreWechatSetting', t('Store WeChat settings'))
      ]
    },
    {
      path: '/huifu-configuration',
      name: 'HuifuConfiguration',
      component: Layout,
      meta: {
        title: t('Huifu configuration'),
        icon: 'el-icon-money',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('HuifuProviderProfile', t('Huifu provider profiles')),
        ...r('HuifuMerchant', t('Huifu merchants')),
        ...r('HuifuMerchantStoreGrant', t('Huifu merchant grants')),
        ...r('StoreHuifuSetting', t('Store Huifu settings')),
        ...r('HuifuPosDevice', t('Huifu POS devices'))
      ]
    },
    {
      path: '/huifu-transactions',
      name: 'HuifuTransactions',
      component: Layout,
      meta: {
        title: t('Huifu transactions'),
        icon: 'el-icon-tickets',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('HuifuPaymentAttempt', t('Huifu payment attempts')),
        ...r('HuifuPosReceipt', t('Huifu POS receipts'))
      ]
    },
    {
      path: '/counter-management',
      name: 'CounterManagement',
      component: Layout,
      meta: {
        title: t('Counter management'),
        icon: 'counter',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('Counter', t('Counters')),
        {
          path: 'counter-orders',
          name: 'CounterOrders',
          component: () => import('./config/features/counter-orders/CounterOrders.vue'),
          meta: { title: t('Counter orders'), icon: 'el-icon-tickets' }
        },
        ...r('OrderCounter', t('Order counter bindings'))
      ]
    }
  ],
  replace: [{
    ...storeRoute,
    children: [
      ...storeRoute.children,
      ...r('Membership', t('Member management'))
    ]
  }, {
    ...orderRoute,
    children: [
      ...orderRoute.children,
      {
        path: 'refund-records',
        name: 'OrderRefundRecords',
        component: OrderRefundRecords,
        meta: { title: t('Order refund records'), icon: 'el-icon-refresh-left' }
      }
    ]
  }, {
    ...promotionRoute,
    children: [
      ...promotionRoute.children,
      {
        path: 'simulation',
        name: 'PromotionSimulation',
        component: () => import('./config/features/promotion-simulation/PromotionSimulation.vue'),
        meta: { title: t('Promotion simulation'), icon: 'el-icon-caret-right' }
      }
    ]
  }],
  remove: []
}
