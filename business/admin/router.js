import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'
import QiniuSetting from './views/QiniuSetting.jsx'

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
  replace: [],
  remove: []
}
