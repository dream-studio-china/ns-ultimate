import { collectEntities } from './collect-entities.mjs'
import Store from './overrides/store/store.js'
import Product from './overrides/product/product.jsx'
import StoreOrder from './overrides/store/store-order.js'
import CounterCollection from './collections/counter/Counter.js'

const collections = import.meta.glob('./collections/**/*.{js,jsx}', { eager: true })
const entities = collectEntities(collections)

for (const [name, config] of Object.entries(CounterCollection)) {
  if (!Object.hasOwn(entities, name)) {
    entities[name] = config
  }
}

export default {
  add: entities,
  replace: { Store, Product, StoreOrder },
  remove: []
}
