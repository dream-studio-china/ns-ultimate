import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'

export default {
  add: [
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
        ...r('OrderCounter', t('Order counter bindings'))
      ]
    }
  ],
  replace: [],
  remove: []
}
