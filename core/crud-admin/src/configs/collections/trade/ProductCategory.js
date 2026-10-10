import { t } from '@/i18n'
import axios from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import ProductCategoryNameField from './ProductCategoryNameField'

export default {
  ProductCategory: {
    form: {
      fields: [
        { property: 'name', required: true, component: ProductCategoryNameField },
        { property: 'slug', required: true, help: t('Product category slug help') },
        { property: 'description', type: 'text', required: false },
        { property: 'parent', required: false },
        { property: 'sortOrder', type: 'number', default_value: 0 },
        { property: 'enabled', type: 'boolean', default_value: true },
        { property: 'store', required: false, help: t('Product category store scope help') }
      ]
    },
    list: {
      list_filter: {
        name: t('Category Name'),
        slug: t('Slug'),
        enabled: {
          label: t('Enabled'),
          type: 'boolean',
          expression: 'entity.getEnabled() == :value'
        },
        'store.id': () => axios
          .get(apiPath(API_PREFIX, 'manage/stores'))
          .then(res => Object.assign({ __label: t('Store') }, ...res.data.map(store => ({ [store.id]: store.name })))),
        'parent.id': () => axios
          .get(apiPath(API_PREFIX, 'manage/product-categories'))
          .then(res => Object.assign({ __label: t('Parent Category') }, ...res.data.map(category => ({ [category.id]: category.name }))))
      },
      list_display: ['id', 'name', 'slug', 'store', 'parent', 'sortOrder', 'enabled']
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
