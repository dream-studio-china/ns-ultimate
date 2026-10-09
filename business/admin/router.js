import { r } from '@/router/generator'
import { t } from '@/i18n'
import Layout from '@/layout'

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
        ...r('Dummy', t('Dummies'))
      ]
    }
  ],
  replace: [],
  remove: []
}
