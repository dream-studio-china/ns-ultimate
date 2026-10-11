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

export default {
  add: [
    {
      path: '/business',
      name: 'BusinessManage',
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
      path: '/qiniu',
      name: 'QiniuManage',
      component: Layout,
      meta: {
        title: t('Qiniu Config'),
        icon: 'el-icon-cloudy',
        roles: ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']
      },
      children: [
        {
          path: '/qiniu/config',
          name: 'QiniuConfig',
          component: QiniuSetting,
          meta: {
            title: t('Qiniu Config'),
            icon: 'el-icon-cloudy'
          }
        }
      ]
    }
  ],
  replace: [storeManageWithCounter],
  remove: []
}
