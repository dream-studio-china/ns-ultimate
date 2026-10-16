import { collectEntities } from './collect-entities.mjs'
import CounterQrCodeAction from './components/CounterQrCodeAction.js'
import Store from './overrides/store/store.js'
import Product from './overrides/product/product.jsx'
import StoreOrder from './overrides/store/store-order.js'
import WechatUser from './overrides/wechat/wechat-user.js'

const collections = import.meta.glob('./collections/**/*.{js,jsx}', { eager: true })
const entities = collectEntities(collections)
if (entities.Counter?.list) {
  entities.Counter.list.actions = [
    ...(entities.Counter.list.actions || []),
    { name: 'counter-qr-code', position: 'list', component: CounterQrCodeAction }
  ]
}

export default {
  add: entities,
  replace: { Store, Product, StoreOrder, WechatUser },
  remove: []
}
