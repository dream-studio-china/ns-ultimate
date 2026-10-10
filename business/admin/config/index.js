import { collectEntities } from './collect-entities.mjs'
import Store from './overrides/store/store.js'
import Product from './overrides/product/product.jsx'
import ProductCategory from './overrides/product-category/productCategory.js'

const collections = import.meta.glob('./collections/**/*.{js,jsx}', { eager: true })
const entities = collectEntities(collections)

export default {
  add: entities,
  replace: { Store, Product, ProductCategory },
  remove: []
}
