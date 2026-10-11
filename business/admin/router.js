import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'

export default {
  add: [
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
    },
    {
      path: '/wechat-management',
      name: 'WechatManagement',
      component: Layout,
      meta: {
        title: t('Wechat management'),
        icon: 'el-icon-chat-dot-round',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        ...r('WechatApplication', t('WeChat applications')),
        ...r('WechatApplicationRevision', t('WeChat application revisions')),
        ...r('WechatMerchant', t('WeChat merchants')),
        ...r('WechatMerchantRevision', t('WeChat merchant revisions')),
        ...r('StoreWechatSetting', t('Store WeChat settings'))
      ]
    }
  ],
  replace: [],
  remove: []
}
