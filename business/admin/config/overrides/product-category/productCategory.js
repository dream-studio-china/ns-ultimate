import coreProductCategory from '../../../../../core/crud-admin/src/configs/collections/trade/ProductCategory'

const config = coreProductCategory.ProductCategory

export default {
  ...config,
  list: {
    ...config.list,
    list_display: config.list.list_display.map(field =>
      field === 'sortOrder'
        ? { property: 'sortOrder', type: 'integer', editable: true }
        : field
    )
  }
}
