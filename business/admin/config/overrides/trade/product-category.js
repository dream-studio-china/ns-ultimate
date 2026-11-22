import coreConfig from '../../../../../core/crud-admin/src/configs/collections/trade/ProductCategory'
import { CopyProductCategoriesAction } from '../../components/catalog/copy-catalog-actions'

const productCategory = coreConfig.ProductCategory

export default {
  ...productCategory,
  list: {
    ...productCategory.list,
    actions: [
      { name: 'copy-product-categories-from-store', position: 'top', component: CopyProductCategoriesAction }
    ]
  }
}
