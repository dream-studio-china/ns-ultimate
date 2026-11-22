<script>
import {
  normalizeStoreUuid,
  productFilterState
} from './product-filter-scope.mjs'

export default {
  name: 'ProductStoreFilterScope',
  props: {
    modelValue: { type: String, default: '' }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(value) {
        productFilterState().storeUuid = normalizeStoreUuid(value)
      }
    }
  },
  render() {
    return null
  }
}
</script>
