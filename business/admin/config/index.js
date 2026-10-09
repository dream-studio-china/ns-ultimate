import { collectEntities } from './collect-entities.mjs'
import Store from './overrides/store.js'
import Product from './overrides/product.jsx'

const collections = import.meta.glob('./collections/**/*.{js,jsx}', { eager: true })
const entities = collectEntities(collections)

export default {
  add: entities,
  replace: { Store, Product },
  remove: []
}
