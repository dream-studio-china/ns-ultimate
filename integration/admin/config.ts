import coreConfig from '../../core/crud-admin/src/configs'
import businessRoutes from '../../business/admin/router'
import businessConfig from '../../business/admin/config/index.js'
import { mergeEntities, mergeRoutes, moveRouteAfter } from './merge-config.mjs'
import installMenuIcons from '../../business/admin/install-menu-icons.js'
import './menu-icons.css'

installMenuIcons()

let routes = mergeRoutes(coreConfig.routes, businessRoutes)
routes = moveRouteAfter(routes, 'CounterManagement', 'ProductManage')
routes = moveRouteAfter(routes, 'WechatConfiguration', 'ContentManage')

export default {
  routes,
  entities: mergeEntities(coreConfig.entities, businessConfig)
}
