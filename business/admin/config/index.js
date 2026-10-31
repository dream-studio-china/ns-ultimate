import { collectEntities } from './collect-entities.mjs'
import Store from './overrides/store/store.js'
import Product from './overrides/product/product.jsx'
import ProductCategory from './overrides/product/product-category.js'
import StoreOrder from './overrides/store/store-order.js'
import Order from './overrides/trade/order.js'
import WechatUser from './overrides/wechat/wechat-user.js'

const collections = import.meta.glob('./collections/**/*.{js,jsx}', { eager: true })
const entities = collectEntities(collections)

export default {
  add: entities,
  replace: { Store, Product, ProductCategory, Order, StoreOrder, WechatUser },
  remove: []
}
