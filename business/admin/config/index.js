import { collectEntities } from './collect-entities.mjs'
import Store from './overrides/store/store.js'
import Product from './overrides/product/product.jsx'
import StoreOrder from './overrides/store/store-order.js'
import Role from './overrides/authorization/role.js'
import Permission from './overrides/authorization/permission.js'
import Assignment from './overrides/authorization/assignment.js'
import User from './overrides/identity/user.js'

const collections = import.meta.glob('./collections/**/*.{js,jsx}', { eager: true })
const entities = collectEntities(collections)

export default {
  add: entities,
  replace: { Store, Product, StoreOrder, Role, Permission, Assignment, User },
  remove: []
}
