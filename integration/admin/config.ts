import coreConfig from '../../core/crud-admin/src/configs'
import businessRoutes from '../../business/admin/router'
import businessConfig from '../../business/admin/config/index.js'
import { mergeEntities, mergeRoutes, moveRouteAfter, moveRouteBefore } from './merge-config.mjs'
import installMenuIcons from '../../business/admin/install-menu-icons.js'
import './menu-icons.css'
import './printer-template-editor.css'
import { initializePalette } from '../../business/admin/appearance/palette.mjs'
import '../../business/admin/appearance/palette.css'
import appearanceRoute from '../../business/admin/appearance/route.mjs'
import { t } from './i18n'

installMenuIcons()
initializePalette()

const systemOptions = coreConfig.routes.find(route => route.name === 'SystemOptionManage')
if (!systemOptions) throw new Error('The admin core must expose the SystemOptionManage route.')

let routes = mergeRoutes(coreConfig.routes, {
  ...businessRoutes,
  replace: [
    ...businessRoutes.replace,
    {
      ...systemOptions,
      children: [...systemOptions.children, { ...appearanceRoute, meta: { ...appearanceRoute.meta, title: t('Appearance settings') } }]
    }
  ]
})
routes = moveRouteBefore(routes, 'StoreManage', 'ProductManage')
routes = moveRouteAfter(routes, 'CounterManagement', 'ProductManage')
routes = moveRouteAfter(routes, 'OrderManage', 'ProductManage')
routes = moveRouteAfter(routes, 'PromotionManage', 'OrderManage')
routes = moveRouteAfter(routes, 'WechatConfiguration', 'ContentManage')
routes = moveRouteAfter(routes, 'HuifuConfiguration', 'WechatConfiguration')
routes = moveRouteAfter(routes, 'HuifuTransactions', 'HuifuConfiguration')
routes = moveRouteAfter(routes, 'PrinterManage', 'ContentManage')

export default {
  routes,
  entities: mergeEntities(coreConfig.entities, businessConfig)
}
