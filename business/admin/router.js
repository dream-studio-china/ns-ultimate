import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'
import coreRoutes from '../../core/crud-admin/src/configs/routes.js'
import QiniuSetting from './views/QiniuSetting.jsx'

const storeManageRoute = coreRoutes.find(route => route.name === 'StoreManage')

if (!storeManageRoute) {
  throw new Error('The core StoreManage route was not found.')
}

const storeManageWithCounter = {
  ...storeManageRoute,
  children: [
    ...(storeManageRoute.children || []),
    ...r('Counter', t('Counter'))
  ]
}

const orderedCoreRoutes = [
  storeManageWithCounter,
  ...coreRoutes.filter(route => route.name !== 'StoreManage')
]

export default {
  add: [
    {
      path: '/business',
      name: 'BusinessManage',
      hidden: true,
      component: Layout,
      meta: {
        title: t('Business'),
        icon: 'el-icon-money',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('PaymentSetting', t('Payment settings'))
      ]
    },
    {
      path: '/counter-management',
      name: 'CounterManagement',
      component: Layout,
      meta: {
        title: t('Counter management'),
        icon: 'el-icon-fork-spoon',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('Counter', t('Counters')),
        ...r('OrderCounter', t('Order counter bindings'))
      ]
    }
  ],
  replace: orderedCoreRoutes,
  remove: []
}
