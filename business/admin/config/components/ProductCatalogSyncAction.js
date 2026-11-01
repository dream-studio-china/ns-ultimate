import { computed, defineComponent, h, ref, resolveComponent, watch } from 'vue'
import { createUiFeedback } from '@/easyadmin/ui/vue/feedback'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'
import request from '@/utils/request'

const endpoint = apiPath(API_PREFIX, 'manage/product-catalog-sync')

export default defineComponent({
  name: 'ProductCatalogSyncAction',
  props: {
    refresh: { type: Function, default: () => {} }
  },
  setup(props) {
    const ElAlert = resolveComponent('ElAlert')
    const ElButton = resolveComponent('ElButton')
    const ElDialog = resolveComponent('ElDialog')
    const ElOption = resolveComponent('ElOption')
    const ElSelect = resolveComponent('ElSelect')
    const visible = ref(false)
    const stores = ref([])
    const sourceStoreUuid = ref('')
    const targetStoreUuid = ref('')
    const result = ref(null)
    const busy = ref('')
    const error = ref('')

    const sameStore = computed(() => Boolean(sourceStoreUuid.value)
      && sourceStoreUuid.value === targetStoreUuid.value)
    const canPreview = computed(() => Boolean(sourceStoreUuid.value && targetStoreUuid.value && !sameStore.value))
    const canSync = computed(() => canPreview.value && Boolean(result.value))

    watch([sourceStoreUuid, targetStoreUuid], () => {
      result.value = null
      error.value = ''
    })

    const failureMessage = cause => cause.response?.data?.message
      || cause.response?.data?.error
      || cause.message
      || t('Catalog sync failed')

    const loadStores = async() => {
      if (stores.value.length) return
      try {
        const response = await request.get(apiPath(API_PREFIX, 'manage/stores'), { params: { limit: 300 } })
        stores.value = Array.isArray(response.data) ? response.data : []
      } catch (cause) {
        error.value = failureMessage(cause)
      }
    }

    const open = () => {
      visible.value = true
      error.value = ''
      loadStores()
    }

    const payload = () => ({
      sourceStoreUuid: sourceStoreUuid.value,
      targetStoreUuid: targetStoreUuid.value
    })

    const preview = async() => {
      if (!canPreview.value || busy.value) return
      busy.value = 'preview'
      error.value = ''
      try {
        const response = await request.post(`${endpoint}/preview`, payload())
        result.value = response.data
      } catch (cause) {
        result.value = null
        error.value = failureMessage(cause)
      } finally {
        busy.value = ''
      }
    }

    const sync = async() => {
      if (!canSync.value || busy.value) return
      busy.value = 'sync'
      error.value = ''
      try {
        const response = await request.post(endpoint, payload())
        const summary = response.data
        result.value = summary
        createUiFeedback().success(t(
          'Catalog sync completed: categories {0}, products {1}',
          summary.categories?.created || 0,
          summary.products?.created || 0
        ))
        props.refresh()
      } catch (cause) {
        error.value = failureMessage(cause)
      } finally {
        busy.value = ''
      }
    }

    const countKey = item => Object.hasOwn(item || {}, 'created') ? 'created' : 'toCreate'
    const select = (model, onUpdate, placeholder) => h(ElSelect, {
      modelValue: model.value,
      'onUpdate:modelValue': value => { onUpdate(value || '') },
      placeholder,
      filterable: true,
      clearable: true,
      style: 'width: 100%'
    }, () => stores.value.map(store => h(ElOption, {
      key: store.uuid,
      label: `${store.name} (${store.code})`,
      value: store.uuid
    })))

    const summary = () => {
      if (!result.value) return null
      const categories = result.value.categories || {}
      const products = result.value.products || {}
      const categoryCreated = categories[countKey(categories)] || 0
      const productCreated = products[countKey(products)] || 0
      const skippedNames = [...(categories.skippedNames || []), ...(products.skippedNames || [])]

      return h('div', {
        style: 'display:grid;gap:8px;padding:14px;border:1px solid var(--el-border-color);border-radius:8px;background:var(--el-fill-color-lighter)'
      }, [
        h('strong', {}, categoryCreated || productCreated ? t('Ready to sync') : t('No changes to sync')),
        h('span', {}, t('Categories to create: {0}', categoryCreated)),
        h('span', {}, t('Categories skipped: {0}', categories.skipped || 0)),
        h('span', {}, t('Products to create: {0}', productCreated)),
        h('span', {}, t('Products skipped: {0}', products.skipped || 0)),
        skippedNames.length
          ? h('span', { style: 'word-break:break-word;color:var(--el-text-color-secondary)' },
            t('Skipped duplicate names: {0}', [...new Set(skippedNames)].join(', ')))
          : null
      ])
    }

    return () => h('span', { style: 'display:inline-flex;align-items:center' }, [
      h(ElButton, {
        size: 'default',
        type: 'primary',
        icon: 'el-icon-refresh',
        plain: true,
        onClick: open
      }, () => t('Batch sync')),
      h(ElDialog, {
        modelValue: visible.value,
        'onUpdate:modelValue': value => { visible.value = value },
        title: t('Sync products and categories'),
        width: '560px',
        appendToBody: true,
        closeOnClickModal: false,
        destroyOnClose: false
      }, {
        default: () => h('div', { style: 'display:grid;gap:16px' }, [
          h('p', { style: 'margin:0;color:var(--el-text-color-secondary)' },
            t('Product catalog sync help')),
          h('label', { style: 'display:grid;gap:6px' }, [
            h('span', {}, t('Source store')),
            select(sourceStoreUuid, value => { sourceStoreUuid.value = value }, t('Select source store'))
          ]),
          h('label', { style: 'display:grid;gap:6px' }, [
            h('span', {}, t('Target store')),
            select(targetStoreUuid, value => { targetStoreUuid.value = value }, t('Select target store'))
          ]),
          sameStore.value ? h(ElAlert, {
            title: t('Source and target stores must be different'),
            type: 'warning',
            showIcon: true,
            closable: false
          }) : null,
          error.value ? h(ElAlert, {
            title: error.value,
            type: 'error',
            showIcon: true,
            closable: false
          }) : null,
          summary()
        ]),
        footer: () => h('div', { style: 'display:flex;justify-content:flex-end;gap:8px' }, [
          h(ElButton, { onClick: () => { visible.value = false } }, () => t('Cancel')),
          h(ElButton, {
            loading: busy.value === 'preview',
            disabled: !canPreview.value || Boolean(busy.value),
            onClick: preview
          }, () => t('Check sync')),
          h(ElButton, {
            type: 'primary',
            loading: busy.value === 'sync',
            disabled: !canSync.value || Boolean(busy.value),
            onClick: sync
          }, () => t('Start sync'))
        ])
      })
    ])
  }
})
