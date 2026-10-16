// Business override of the core Product entity (declared explicitly in
// business/admin/config/index.js `replace`). Adds category binding and
// metadata-backed display fields (cover, recommendation, kitchen print, sold-out, order, and sale schedule).
// List and specifications behavior mirror the core configuration.
import { defineAsyncComponent } from 'vue'
import { t } from '@/i18n'
import axios from '@/utils/request'
import SIP from '@/utils/simple-image-process'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { orderByIdDesc, statusFilterLabel } from '../../../../../core/crud-admin/src/configs/collections/helpers'
// Lazily resolve the admin UI here: this config module is eagerly pulled into
// every FormAdmin through `@/configs/entities`, so static SFC imports would
// close an eager cycle (FormAdmin -> entities -> Product.jsx -> ListAdmin ->
// FormAdmin) and blow up with "Cannot access 'FormAdmin' before
// initialization" depending on module evaluation order.
const ListAdmin = defineAsyncComponent(() => import('@/easyadmin/ui/vue/ListAdmin'))
const FormAdmin = defineAsyncComponent(() => import('@/easyadmin/ui/vue/FormAdmin'))
import specificationConfig from '../../../../../core/crud-admin/src/configs/collections/trade/Specification'
import ProductMetadataSchema from './ProductMetadata.json'

const RecommendationLevelInput = {
  props: ['form', 'field'],
  render() {
    const rawValue = Number(this.form[this.field.property]) || 0
    const value = Math.max(0, Math.min(10, Math.floor(rawValue)))
    return (
      <div style={{ display: 'inline-flex', alignItems: 'center', flexWrap: 'wrap', gap: '8px' }} role={'group'} aria-label={t('Recommendation level')}>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '2px' }}>
          {Array.from({ length: 10 }, (_, index) => {
            const level = index + 1
            const selected = level <= value
            return (
              <button
                key={level}
                type={'button'}
                title={`${t('Set recommendation level')} ${level}`}
                aria-label={`${t('Set recommendation level')} ${level}`}
                aria-pressed={selected}
                onClick={() => { this.form[this.field.property] = level }}
                style={{ border: 0, padding: '0 1px', background: 'transparent', color: selected ? '#D6A31E' : '#AAB2BD', cursor: 'pointer', fontSize: '22px', lineHeight: 1 }}
              >★</button>
            )
          })}
        </span>
        <span>{value ? `${value} / 10` : t('Not recommended')}</span>
        {value ? <el-button size={'small'} link onClick={() => { this.form[this.field.property] = null }}>{t('Clear recommendation level')}</el-button> : null}
      </div>
    )
  }
}

const ProductNameWithCover = {
  props: ['data', 'record'],
  render() {
    const cover = this.record?.metadata?.cover
    const rawLevel = Number(this.record?.metadata?.recommendLevel) || 0
    const recommendationLevel = Math.max(0, Math.min(10, Math.floor(rawLevel)))
    return (
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px' }}>
        {cover ? <el-image src={SIP.getPicture(cover)} fit={'cover'} style={{ width: '40px', height: '40px', borderRadius: '4px', flexShrink: 0 }} /> : null}
        <span>{this.data}</span>
        {recommendationLevel ? <span title={`${t('Recommendation level')}: ${recommendationLevel} / 10`} aria-label={`${t('Recommendation level')} ${recommendationLevel} / 10`} style={{ color: '#D6A31E', whiteSpace: 'nowrap' }}>{'★'.repeat(recommendationLevel)} <small>{recommendationLevel}/10</small></span> : null}
      </span>
    )
  }
}

const SpecificationManager = {
  components: { ListAdmin, FormAdmin },
  props: ['form', 'data', 'property', 'fields', 'field'],
  data() {
    return {
      dialogShow: false,
      specId: null,
      refreshKey: 0,
      specForm: {}
    }
  },
  computed: {
    productId() {
      let parent = this.$parent
      while (parent) {
        if (parent.$options.name === 'FormAdmin') {
          return Number(parent.id) || 0
        }
        parent = parent.$parent
      }
      return Number(this.$route?.params?.id) || 0
    },
    specEntityConf() {
      return {
        name: 'Specification',
        prefix: `/api/v1/manage/products/${this.productId}`,
        plural: 'specifications'
      }
    },
    inlineFields() {
      return specificationConfig.Specification.form.fields.filter(f =>
        typeof f === 'string' ? ['name', 'price', 'sort'].includes(f) : ['name', 'price', 'sort'].includes(f.property)
      )
    }
  },
  created() {
    if (!Array.isArray(this.form.specifications)) {
      this.form.specifications = []
    }
  },
  render() {
    const spec = specificationConfig.Specification

    if (!this.productId) {
      return (
        <div>
          <el-button
            size={'default'} type={'primary'} icon={'el-icon-plus'} plain
            onClick={() => { this.form.specifications.push({}) }}
          >{t('Add')}</el-button>
          {this.form.specifications.map((item, index) => (
            <div key={index}>
              <FormAdmin
                modelValue={this.form.specifications[index]}
                {...{ 'onUpdate:modelValue': v => { this.form.specifications[index] = v } }}
                entity-conf={'Specification'}
                fields={this.inlineFields}
                v-slots={{ action: () => <span /> }}
              />
              <p style={{ textAlign: 'right' }}>
                <el-button
                  type={'danger'} icon={'el-icon-delete'} circle
                  onClick={() => { this.form.specifications.splice(index, 1) }}
                />
              </p>
            </div>
          ))}
        </div>
      )
    }

    return (
      <div>
        <ListAdmin
          key={this.refreshKey}
          entity-conf={this.specEntityConf}
          list-display={spec.list.list_display}
          list-filter={spec.list.list_filter}
          query={{ '@order': 'entity.sort|ASC, entity.id|DESC' }}
          v-slots={{
            topButton: () => (
              <el-button
                size={'default'} type={'primary'} icon={'el-icon-plus'} plain
                onClick={() => {
                  this.specId = null
                  this.specForm = { product: this.productId }
                  this.refreshKey++
                  this.dialogShow = true
                }}
              >{t('New')}</el-button>
            ),
            'action:edit': ({ data }) => (
              <el-button
                size={'small'} icon={'el-icon-edit'} plain
                onClick={() => {
                  this.specId = data.id
                  this.specForm = { product: this.productId }
                  this.refreshKey++
                  this.dialogShow = true
                }}
              >{t('Edit')}</el-button>
            )
          }}
        />

        <el-dialog
          title={this.specId ? t('Update Spec') : t('New Spec')}
          modelValue={this.dialogShow}
          alignCenter={true}
          appendToBody={true}
          destroyOnClose={true}
          {...{
            'onUpdate:modelValue': v => { this.dialogShow = v },
            onClosed: () => { this.refreshKey++ }
          }}
          width={'40%'}
        >
          <FormAdmin
            key={this.refreshKey}
            id={this.specId}
            modelValue={this.specForm}
            {...{ 'onUpdate:modelValue': v => { this.specForm = v } }}
            entity-conf={this.specEntityConf}
            fields={spec.form.fields}
            v-slots={{
              action: ({ submit }) => (
                <el-button
                  type={'primary'} icon={'el-icon-edit-outline'}
                  onClick={() => {
                    submit(() => {
                      this.$message({ message: t('Data saved successfully'), type: 'success' })
                      this.refreshKey++
                      this.dialogShow = false
                      this.specForm = { product: this.productId }
                    })
                  }}
                >{t('Save')}</el-button>
              )
            }}
          />
        </el-dialog>
      </div>
    )
  }
}

export default {
    form: {
      fields: [
        'name',
        { property: 'description', type: 'text', required: false },
        { property: 'category', required: false, help: t('Product category help') },
        { property: 'store', required: false, help: t('Product store help') },
        { property: 'status', type: 'select', default_value: 'active', help: t('Product status help'), type_options: {
          options: [
            { value: 'active', label: t('Active') },
            { value: 'inactive', label: t('Inactive') }
          ]
        }},
        {
          property: 'metadata',
          type: 'json_schema',
          required: false,
          type_options: {
            schema: ProductMetadataSchema,
            fields: [
              { property: 'cover', type: 'image', type_options: { storage: 'qiniu' }, help: t('Product cover help') },
              { property: 'recommendLevel', component: RecommendationLevelInput, field_options: { label: t('Recommendation level') } },
              { property: 'kitchenPrintEnabled', field_options: { label: t('Kitchen print enabled') } },
              { property: 'soldout', field_options: { label: t('Kitchen and ordering sold out') } },
              { property: 'listOrder', field_options: { label: t('Kitchen display order') } },
              { property: 'startSaleTime', field_options: { label: t('Sale start time') }, type_options: { type: 'time' } },
              { property: 'endSaleTime', field_options: { label: t('Sale end time') }, type_options: { type: 'time' } }
            ]
          },
          help: t('Product metadata help')
        },
        {
          property: 'specifications',
          tab: t('Specifications'),
          required: false,
          field_options: { label: '', 'label-width': '60px' },
          component: SpecificationManager
        }
      ],
      batch_edit: {
        fields: [
          {
            property: 'store',
            type: 'RelationToOne',
            relation: { entity: 'Store', plural: 'stores', valueKey: 'uuid', multiple: false },
            field_options: { label: t('Store') },
            help: t('Product store help')
          }
        ]
      }
    },
    list: {
      query: orderByIdDesc,
      list_filter: {
        name: t('Product Name'),
        'category.id': () => axios
          .get(apiPath(API_PREFIX, 'manage/product-categories'))
          .then(res => Object.assign({ __label: t('Category') }, ...res.data.map(category => ({ [category.id]: category.name })))),
        status: statusFilterLabel(),
        isDeleted: {
          label: t('Deleted'),
          type: 'boolean',
          expression: 'entity.getIsDeleted() == :value'
        }
      },
      list_display: [
        'id',
        { property: 'name', component: ProductNameWithCover },
        'category', 'store', 'status', 'isDeleted', 'createdAt', 'updatedAt'
      ]
    },
    detail: {
      detail_display: [
        'id',
        'name',
        'store',
        'category',
        { property: 'description', type: 'text', span: 2, full_width: true },
        'status',
        'createdAt',
        'updatedAt',
        {
          property: 'metadata',
          type: 'json_schema',
          span: 2,
          full_width: true,
          type_options: {
            schema: ProductMetadataSchema,
            fields: [
              { property: 'cover', type: 'image', type_options: { storage: 'qiniu' } },
              { property: 'kitchenPrintEnabled', field_options: { label: t('Kitchen print enabled') } },
              { property: 'soldout', field_options: { label: t('Kitchen and ordering sold out') } },
              { property: 'listOrder', field_options: { label: t('Kitchen display order') } },
              { property: 'startSaleTime', field_options: { label: t('Sale start time') }, type_options: { type: 'time' } },
              { property: 'endSaleTime', field_options: { label: t('Sale end time') }, type_options: { type: 'time' } }
            ]
          }
        },
        { property: 'metadata.cover', label: t('Product cover'), type: 'image', span: 2, full_width: true }
      ]
    }
}
