// Business override of the core Product entity (declared explicitly in
// business/admin/config/index.js `replace`). Adds category binding and
// metadata-backed display fields (sold out / recommendation 1-10 / cover).
// List and specifications behavior mirror the core configuration.
import { defineAsyncComponent } from 'vue'
import { t } from '@/i18n'
import axios from '@/utils/request'
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
              { property: 'cover', type: 'image', type_options: { storage: 'qiniu' }, help: t('Product cover help') }
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
      ]
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
      list_display: ['id', 'name', 'category', 'status', 'isDeleted', 'createdAt', 'updatedAt']
    },
    detail: {
      detail_display: '__all__'
    }
}
