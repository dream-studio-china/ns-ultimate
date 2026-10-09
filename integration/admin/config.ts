import coreConfig from '../../core/crud-admin/src/configs'
import businessRoutes from '../../business/admin/router'
import businessConfig from '../../business/admin/config/index.js'
import { mergeEntities, mergeRoutes } from './merge-config.mjs'

export default {
  routes: mergeRoutes(coreConfig.routes, businessRoutes),
  entities: mergeEntities(coreConfig.entities, businessConfig)
}
