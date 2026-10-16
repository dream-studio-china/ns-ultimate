import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'

export default {
  add: [
    {
      path: '/store-workspace',
      name: 'StoreWorkspace',
      component: Layout,
      redirect: '/store-workspace/overview',
      meta: {
        title: t('Store performance dashboard'),
        icon: 'el-icon-data-line'
      },
      children: [
        {
          path: 'overview',
          name: 'StorePerformanceOverview',
          component: () => import('./views/store/StoreWorkspacePage.vue'),
          meta: { title: t('Performance overview'), icon: 'el-icon-s-data' }
        },
        {
          path: 'products',
          name: 'StoreWorkspaceProducts',
          component: () => import('./views/store/StoreWorkspacePage.vue'),
          meta: { title: t('Store products'), icon: 'el-icon-goods' }
        },
        {
          path: 'counters',
          name: 'StoreWorkspaceCounters',
          component: () => import('./views/store/StoreWorkspacePage.vue'),
          meta: { title: t('Store counters'), icon: 'counter' }
        },
        {
          path: 'orders',
          name: 'StoreWorkspaceOrders',
          component: () => import('./views/store/StoreWorkspacePage.vue'),
          meta: { title: t('Store orders'), icon: 'el-icon-list' }
        },
        {
          path: 'employees',
          name: 'StoreWorkspaceEmployees',
          component: () => import('./views/store/StoreWorkspacePage.vue'),
          meta: { title: t('Store employees'), icon: 'el-icon-user' }
        }
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
        ...r('OrderCounter', t('Order counter bindings'))
      ]
    }
  ],
  replace: [],
  remove: []
}
