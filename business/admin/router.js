import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'
import QiniuSetting from './views/QiniuSetting.jsx'

export default {
  add: [
    {
      path: '/business',
      name: 'BusinessManage',
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
  replace: [],
  remove: []
}
