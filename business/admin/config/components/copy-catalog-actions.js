import { h } from 'vue'
import CopyCatalogAction from './CopyCatalogAction.vue'

const catalogCopyAction = mode => ({
  props: {
    refresh: { type: Function, default: () => {} }
  },
  render() {
    return h(CopyCatalogAction, { mode, refresh: this.refresh })
  }
})

export const CopyProductCategoriesAction = catalogCopyAction('categories')
export const CopyProductsAction = catalogCopyAction('products')
