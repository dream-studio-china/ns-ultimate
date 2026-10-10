import { defineComponent, h } from 'vue'
import { ElInput } from 'element-plus'
import { pinyin } from 'pinyin-pro'
import { CrudSkeletonAdapter } from '@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter'

let categories

const getCategories = () => {
  if (!categories) categories = new CrudSkeletonAdapter({ name: 'ProductCategory' })
  return categories
}

export function slugFromName(name) {
  return String(name || '')
    .replace(/[\p{Script=Han}]+/gu, text => pinyin(text, { toneType: 'none', type: 'array' }).join('-'))
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLocaleLowerCase()
    .trim()
    .replace(/[^\p{L}\p{N}]+/gu, '-')
    .replace(/^-+|-+$/g, '')
}

export async function isCategorySlugAvailable(slug, store) {
  const escapedSlug = slug.replaceAll('\\', '\\\\').replaceAll("'", "\\'")
  const storeId = typeof store === 'object' && store !== null ? store.id : store
  const storeUuid = typeof store === 'object' && store !== null ? store.uuid : null
  const scopeFilter = storeId !== null && typeof storeId !== 'undefined' && storeId !== '' &&
    (typeof storeId === 'number' || /^\d+$/.test(String(storeId)))
    ? `entity.getStore().getId() == ${Number(storeId)}`
    : storeUuid
      ? `entity.getStore().getUuid() == '${String(storeUuid).replaceAll("'", "\\'")}'`
      : typeof store === 'string' && store !== ''
        ? `entity.getStore().getUuid() == '${store.replaceAll("'", "\\'")}'`
        : 'entity.getStore() == null'
  const response = await getCategories().list({
    '@filter': `entity.getSlug() == '${escapedSlug}' && ${scopeFilter}`,
    page: 1,
    limit: 1
  })

  return Array.isArray(response?.data) && response.data.length === 0
}

export async function uniqueCategorySlug(name, store) {
  const baseSlug = slugFromName(name)
  if (!baseSlug) return ''

  for (let suffix = 1; suffix <= 1000; suffix++) {
    const candidate = suffix === 1 ? baseSlug : `${baseSlug}-${suffix}`
    if (await isCategorySlugAvailable(candidate, store)) return candidate
  }

  return ''
}

export async function updateCategoryName(form, property, value, sequenceState) {
  form[property] = value
  if (String(form.slug || '').trim() !== '') return

  const sequence = ++sequenceState.value
  try {
    const slug = await uniqueCategorySlug(value, form.store)
    if (slug && sequence === sequenceState.value && String(form.slug || '').trim() === '') {
      form.slug = slug
    }
  } catch {
    // Leave the slug empty when uniqueness cannot be confirmed.
  }
}

export default defineComponent({
  name: 'ProductCategoryNameField',
  props: {
    form: { type: Object, required: true },
    property: { type: String, required: true }
  },
  setup(props) {
    const sequenceState = { value: 0 }
    const updateName = value => updateCategoryName(props.form, props.property, value, sequenceState)
    return () => h(ElInput, {
      modelValue: props.form[props.property] || '',
      'onUpdate:modelValue': updateName
    })
  }
})
