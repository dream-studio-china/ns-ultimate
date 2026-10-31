import ProductCategory from '../../../../../core/crud-admin/src/configs/collections/trade/ProductCategory.js'
import ProductCatalogSyncAction from '../../components/ProductCatalogSyncAction.js'

const config = ProductCategory.ProductCategory

export default {
  ...config,
  list: {
    ...config.list,
    actions: [
      ...(config.list.actions || []),
      { name: 'product-catalog-sync', position: 'top', component: ProductCatalogSyncAction }
    ]
  }
}
