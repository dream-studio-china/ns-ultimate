<template>
  <section class="dining-modes" :aria-label="$t('Dining modes')">
    <div class="dining-modes__header">
      <div>
        <strong>{{ $t('Dining modes') }}</strong>
        <p>{{ $t('Dining modes help') }}</p>
      </div>
      <el-button type="primary" plain icon="el-icon-plus" @click="addMode">
        {{ $t('Add dining mode') }}
      </el-button>
    </div>

    <el-alert
      v-if="!storeUuid"
      :title="$t('Save the store before selecting products')"
      type="info"
      :closable="false"
      show-icon
    />
    <el-alert
      v-if="loading"
      :title="$t('Loading products and categories')"
      type="info"
      :closable="false"
      show-icon
    />
    <el-alert
      v-else-if="loadError"
      :title="loadError"
      type="error"
      :closable="false"
      show-icon
    >
      <el-button link type="primary" @click="loadCatalog">{{ $t('Retry') }}</el-button>
    </el-alert>

    <el-empty v-if="modes.length === 0" :description="$t('No dining modes configured')">
      <el-button type="primary" @click="addMode">{{ $t('Add first dining mode') }}</el-button>
    </el-empty>

    <el-tabs
      v-else
      v-model="activeModeUuid"
      :class="['dining-modes__tabs', { 'dining-modes__tabs--single': modes.length === 1 }]"
      :type="modes.length > 1 ? 'border-card' : ''"
    >
      <el-tab-pane
        v-for="(entry, displayIndex) in sortedModeEntries"
        :key="entry.mode.uuid"
        :name="entry.mode.uuid"
      >
        <template #label>
          <span class="mode-tab-label">
            <span class="mode-tab-label__name">{{ entry.mode.name || $t('Unnamed dining mode') }}</span>
            <span class="mode-tab-label__meta">{{ $t('Priority') }} {{ entry.mode.priority }}</span>
            <span v-if="!entry.mode.enabled" class="mode-tab-label__disabled">{{ $t('Disabled') }}</span>
          </span>
        </template>

        <article class="mode-card">
          <header class="mode-card__header">
            <div class="mode-card__heading">
              <div class="mode-card__title">
                <strong>{{ entry.mode.name || $t('Unnamed dining mode') }}</strong>
                <el-tag :type="entry.mode.enabled ? 'success' : 'info'" size="small">
                  {{ $t(entry.mode.enabled ? 'Enabled' : 'Disabled') }}
                </el-tag>
              </div>
              <span class="mode-card__summary">{{ modeSummary(entry.mode) }}</span>
            </div>
            <div class="mode-card__actions">
              <el-switch
                v-model="entry.mode.enabled"
                :active-text="$t('Enabled')"
                :inactive-text="$t('Disabled')"
              />
              <el-button-group>
                <el-button
                  size="small"
                  icon="el-icon-top"
                  :disabled="displayIndex === 0"
                  :title="$t('Move up')"
                  @click="moveMode(entry.mode, -1)"
                />
                <el-button
                  size="small"
                  icon="el-icon-bottom"
                  :disabled="displayIndex === sortedModeEntries.length - 1"
                  :title="$t('Move down')"
                  @click="moveMode(entry.mode, 1)"
                />
              </el-button-group>
              <el-button size="small" type="danger" plain @click="removeMode(entry.mode)">
                {{ $t('Delete') }}
              </el-button>
            </div>
          </header>

          <div class="mode-card__body">
            <section class="mode-section">
              <h4>{{ $t('Basic settings') }}</h4>
              <div class="mode-grid mode-grid--three">
                <label class="mode-field">
                  <span>{{ $t('Mode name') }}</span>
                  <el-input v-model="entry.mode.name" maxlength="60" :placeholder="$t('For example Hotpot or Breakfast')" />
                </label>
                <label class="mode-field">
                  <span>{{ $t('Priority') }}</span>
                  <el-input-number v-model="entry.mode.priority" :min="0" :max="9999" :step="10" controls-position="right" />
                  <small>{{ $t('Higher priority is selected first') }}</small>
                </label>
                <label class="mode-field">
                  <span>{{ $t('Switch button label') }}</span>
                  <el-input v-model="entry.mode.switchButtonLabel" maxlength="60" :placeholder="$t('For example Only breakfast')" />
                  <small>{{ $t('Leave empty to use the mode name') }}</small>
                </label>
              </div>
            </section>

          <section class="mode-section">
            <div class="mode-section__title">
              <div>
                <h4>{{ $t('Sales time') }}</h4>
                <p>{{ $t('Sales time uses the store timezone') }}</p>
              </div>
              <el-switch
                v-model="entry.mode.availability.allDay"
                :active-text="$t('All day')"
                :inactive-text="$t('Limited time')"
              />
            </div>
            <el-checkbox-group v-model="entry.mode.availability.daysOfWeek" class="weekday-group">
              <el-checkbox v-for="day in weekdays" :key="day.value" :value="day.value">
                {{ $t(day.label) }}
              </el-checkbox>
            </el-checkbox-group>
            <template v-if="!entry.mode.availability.allDay">
              <div v-if="entry.mode.availability.timeWindows.length === 0" class="empty-inline">
                {{ $t('Add at least one sales time window') }}
              </div>
              <div
                v-for="(window, windowIndex) in entry.mode.availability.timeWindows"
                :key="windowIndex"
                class="time-window"
              >
                <span>{{ $t('From') }}</span>
                <el-time-picker
                  v-model="window.start"
                  format="HH:mm"
                  value-format="HH:mm"
                  :clearable="false"
                  :placeholder="$t('Start time')"
                />
                <span>{{ $t('To') }}</span>
                <el-time-picker
                  v-model="window.end"
                  format="HH:mm"
                  value-format="HH:mm"
                  :clearable="false"
                  :placeholder="$t('End time')"
                />
                <el-button type="danger" link @click="removeTimeWindow(entry.mode, windowIndex)">
                  {{ $t('Remove') }}
                </el-button>
              </div>
              <el-button plain @click="addTimeWindow(entry.mode)">
                {{ $t('Add time window') }}
              </el-button>
            </template>
            <p class="mode-note">{{ $t('End time is exclusive') }}</p>
          </section>

          <section class="mode-section">
            <div class="mode-section__title">
              <div>
                <h4>{{ $t('Products in this mode') }}</h4>
                <p>{{ $t('Choose categories for broad coverage or individual products for exceptions') }}</p>
              </div>
            </div>
            <div class="mode-grid">
              <label class="mode-field">
                <span>{{ $t('Product categories') }}</span>
                <el-select
                  v-model="entry.mode.catalog.categoryUuids"
                  multiple
                  filterable
                  collapse-tags
                  :disabled="!storeUuid"
                  :placeholder="$t('Select product categories')"
                >
                  <el-option
                    v-for="category in categoryOptions"
                    :key="category.uuid"
                    :value="category.uuid"
                    :label="categoryLabel(category)"
                  />
                </el-select>
              </label>
              <label class="mode-field">
                <span>{{ $t('Individual products') }}</span>
                <el-select
                  v-model="entry.mode.catalog.productUuids"
                  multiple
                  filterable
                  collapse-tags
                  :disabled="!storeUuid"
                  :placeholder="$t('Select individual products')"
                >
                  <el-option
                    v-for="product in productOptions"
                    :key="product.uuid"
                    :value="product.uuid"
                    :label="productLabel(product)"
                  />
                </el-select>
              </label>
            </div>
          </section>

          <section class="mode-section">
            <div class="mode-section__title">
              <div>
                <h4>{{ $t('Order rules') }}</h4>
                <p>{{ $t('Rules run only after this mode is selected') }}</p>
              </div>
              <div class="rule-actions">
                <el-button size="small" @click="addRule(entry.mode, 'fixed_item')">
                  {{ $t('Add fixed item') }}
                </el-button>
                <el-button size="small" @click="addRule(entry.mode, 'per_guest_item')">
                  {{ $t('Add per-guest item') }}
                </el-button>
                <el-button size="small" @click="addRule(entry.mode, 'choice_group')">
                  {{ $t('Add choice group') }}
                </el-button>
              </div>
            </div>

            <div v-if="entry.mode.rules.length === 0" class="empty-inline">
              {{ $t('No rules. Products in this mode can be ordered directly') }}
            </div>

            <article v-for="(rule, ruleIndex) in entry.mode.rules" :key="rule.uuid" class="rule-card">
              <header class="rule-card__header">
                <span class="rule-card__index" aria-hidden="true">{{ ruleIndex + 1 }}</span>
                <el-select
                  :model-value="rule.type"
                  class="rule-type"
                  @update:model-value="changeRuleType(entry.mode, ruleIndex, $event)"
                >
                  <el-option value="fixed_item" :label="$t('Fixed default item')" />
                  <el-option value="per_guest_item" :label="$t('Quantity by guest count')" />
                  <el-option value="choice_group" :label="$t('Choose N from a group')" />
                </el-select>
                <el-input
                  v-model="rule.name"
                  maxlength="60"
                  :placeholder="$t('Optional rule name')"
                  class="rule-name"
                />
                <div class="rule-card__actions">
                  <el-button-group>
                    <el-button
                      size="small"
                      icon="el-icon-top"
                      :disabled="ruleIndex === 0"
                      @click="moveRule(entry.mode, ruleIndex, -1)"
                    />
                    <el-button
                      size="small"
                      icon="el-icon-bottom"
                      :disabled="ruleIndex === entry.mode.rules.length - 1"
                      @click="moveRule(entry.mode, ruleIndex, 1)"
                    />
                  </el-button-group>
                  <el-button type="danger" link @click="removeRule(entry.mode, ruleIndex)">
                    {{ $t('Delete') }}
                  </el-button>
                </div>
              </header>

              <div v-if="rule.type === 'choice_group'" class="rule-body">
                <label class="mode-field">
                  <span>{{ $t('Selection source') }}</span>
                  <el-radio-group v-model="rule.targetType">
                    <el-radio value="category">{{ $t('By category') }}</el-radio>
                    <el-radio value="specifications">{{ $t('Specific product specifications') }}</el-radio>
                  </el-radio-group>
                </label>
                <label v-if="rule.targetType === 'category'" class="mode-field">
                  <span>{{ $t('Product category') }}</span>
                  <el-select v-model="rule.categoryUuid" filterable :disabled="!storeUuid" :placeholder="$t('Select product category')">
                    <el-option
                      v-for="category in categoryOptions"
                      :key="category.uuid"
                      :value="category.uuid"
                      :label="categoryLabel(category)"
                    />
                  </el-select>
                </label>
                <label v-else class="mode-field mode-field--wide">
                  <span>{{ $t('Selectable specifications') }}</span>
                  <div class="spec-picker">
                    <el-select
                      v-model="rule.specificationUuids"
                      multiple
                      filterable
                      collapse-tags
                      collapse-tags-tooltip
                      :disabled="!storeUuid"
                      :placeholder="$t('Select product specifications')"
                    >
                      <el-option-group
                        v-for="group in specificationGroups"
                        :key="group.uuid"
                        :label="group.label"
                      >
                        <el-option
                          v-for="specification in group.specifications"
                          :key="specification.uuid"
                          :value="specification.uuid"
                          :label="specification.searchLabel"
                        >
                          <span class="spec-option">
                            <span class="spec-option__name">{{ specification.specificationName }}</span>
                            <span v-if="specification.isGlobal" class="spec-option__scope">{{ $t('Global') }}</span>
                          </span>
                        </el-option>
                      </el-option-group>
                    </el-select>
                    <small class="spec-picker__help">{{ $t('Specifications are grouped by product. Selected specifications are listed separately.') }}</small>
                    <div v-if="rule.specificationUuids.length" class="spec-picker__selected">
                      <div
                        v-for="specification in selectedSpecifications(rule.specificationUuids)"
                        :key="specification.uuid"
                        class="spec-chip"
                      >
                        <span class="spec-chip__main">
                          <strong>{{ specification.productName }}</strong>
                          <span>{{ specification.specificationName }}</span>
                        </span>
                        <span v-if="specification.isGlobal" class="spec-chip__scope">{{ $t('Global') }}</span>
                        <button
                          type="button"
                          class="spec-chip__remove"
                          :aria-label="$t('Remove specification')"
                          :title="$t('Remove specification')"
                          @click="removeChoiceSpecification(rule, specification.uuid)"
                        >×</button>
                      </div>
                    </div>
                  </div>
                </label>
                <div class="rule-grid">
                  <label class="mode-field">
                    <span>{{ $t('Minimum selections') }}</span>
                    <el-input-number v-model="rule.minSelections" :min="0" :max="99" controls-position="right" />
                  </label>
                  <label class="mode-field">
                    <span>{{ $t('Maximum selections') }}</span>
                    <el-input-number v-model="rule.maxSelections" :min="1" :max="99" controls-position="right" />
                  </label>
                  <label class="mode-field">
                    <span>{{ $t('Quantity per selection') }}</span>
                    <el-input-number v-model="rule.quantityPerSelection" :min="1" :max="99" controls-position="right" />
                  </label>
                </div>
              </div>

              <div v-else class="rule-body rule-grid">
                <label class="mode-field mode-field--wide">
                  <span>{{ $t('Product specification') }}</span>
                  <div class="spec-picker">
                    <el-select
                      v-model="rule.specificationUuid"
                      filterable
                      :disabled="!storeUuid"
                      :placeholder="$t('Select product specification')"
                    >
                      <el-option-group
                        v-for="group in specificationGroups"
                        :key="group.uuid"
                        :label="group.label"
                      >
                        <el-option
                          v-for="specification in group.specifications"
                          :key="specification.uuid"
                          :value="specification.uuid"
                          :label="specification.searchLabel"
                        >
                          <span class="spec-option">
                            <span class="spec-option__name">{{ specification.specificationName }}</span>
                            <span v-if="specification.isGlobal" class="spec-option__scope">{{ $t('Global') }}</span>
                          </span>
                        </el-option>
                      </el-option-group>
                    </el-select>
                    <div v-if="specificationFor(rule.specificationUuid)" class="spec-picker__selected">
                      <div class="spec-chip">
                        <span class="spec-chip__main">
                          <strong>{{ specificationFor(rule.specificationUuid).productName }}</strong>
                          <span>{{ specificationFor(rule.specificationUuid).specificationName }}</span>
                        </span>
                        <span v-if="specificationFor(rule.specificationUuid).isGlobal" class="spec-chip__scope">{{ $t('Global') }}</span>
                      </div>
                    </div>
                  </div>
                </label>
                <label class="mode-field">
                  <span>{{ $t(rule.type === 'per_guest_item' ? 'Quantity per guest' : 'Default quantity') }}</span>
                  <el-input-number
                    v-if="rule.type === 'per_guest_item'"
                    v-model="rule.quantityPerGuest"
                    :min="1"
                    :max="99"
                    controls-position="right"
                  />
                  <el-input-number v-else v-model="rule.quantity" :min="1" :max="99" controls-position="right" />
                </label>
              </div>

              <footer class="rule-card__footer">
                <el-checkbox v-model="rule.required">{{ $t('Required') }}</el-checkbox>
                <el-checkbox v-model="rule.allowRemove">{{ $t('Customer can remove') }}</el-checkbox>
                <span>{{ ruleDescription(rule) }}</span>
              </footer>
            </article>
          </section>

          <section class="mode-section">
            <div class="mode-section__title">
              <div>
                <h4>{{ $t('Checkout rules') }}</h4>
                <p>{{ $t('Checkout rules help') }}</p>
              </div>
              <div class="rule-actions">
                <el-button size="small" @click="addCheckoutRule(entry.mode, 'confirm_participants')">
                  {{ $t('Add guest confirmation') }}
                </el-button>
                <el-button size="small" @click="addCheckoutRule(entry.mode, 'confirm_required_items')">
                  {{ $t('Add required item review') }}
                </el-button>
                <el-button size="small" @click="addCheckoutRule(entry.mode, 'suggest_category')">
                  {{ $t('Add category suggestion') }}
                </el-button>
              </div>
            </div>

            <div v-if="entry.mode.checkoutRules.length === 0" class="empty-inline">
              {{ $t('No checkout rules. Checkout shows order items and amount only') }}
            </div>

            <article
              v-for="(rule, checkoutRuleIndex) in entry.mode.checkoutRules"
              :key="rule.uuid"
              class="rule-card checkout-rule-card"
            >
              <header class="rule-card__header">
                <span class="rule-card__index" aria-hidden="true">{{ checkoutRuleIndex + 1 }}</span>
                <el-select
                  :model-value="rule.type"
                  class="rule-type"
                  @update:model-value="changeCheckoutRuleType(entry.mode, checkoutRuleIndex, $event)"
                >
                  <el-option value="confirm_participants" :label="$t('Guest count confirmation')" />
                  <el-option value="confirm_required_items" :label="$t('Required item review')" />
                  <el-option value="suggest_category" :label="$t('Category suggestion')" />
                </el-select>
                <el-input
                  v-model="rule.name"
                  maxlength="60"
                  :placeholder="$t('Checkout rule name')"
                  class="rule-name"
                />
                <div class="rule-card__actions">
                  <el-button-group>
                    <el-button
                      size="small"
                      icon="el-icon-top"
                      :disabled="checkoutRuleIndex === 0"
                      @click="moveCheckoutRule(entry.mode, checkoutRuleIndex, -1)"
                    />
                    <el-button
                      size="small"
                      icon="el-icon-bottom"
                      :disabled="checkoutRuleIndex === entry.mode.checkoutRules.length - 1"
                      @click="moveCheckoutRule(entry.mode, checkoutRuleIndex, 1)"
                    />
                  </el-button-group>
                  <el-button type="danger" link @click="removeCheckoutRule(entry.mode, checkoutRuleIndex)">
                    {{ $t('Delete') }}
                  </el-button>
                </div>
              </header>

              <div class="rule-body rule-grid">
                <label class="mode-field">
                  <span>{{ $t('Display title') }}</span>
                  <el-input
                    v-model="rule.title"
                    maxlength="120"
                    :placeholder="$t('Checkout display title placeholder')"
                  />
                </label>
                <label v-if="rule.type === 'suggest_category'" class="mode-field">
                  <span>{{ $t('Suggested product category') }}</span>
                  <el-select
                    v-model="rule.categoryUuid"
                    filterable
                    :disabled="!storeUuid"
                    :placeholder="$t('Select suggested product category')"
                  >
                    <el-option
                      v-for="category in categoryOptions"
                      :key="category.uuid"
                      :value="category.uuid"
                      :label="categoryLabel(category)"
                    />
                  </el-select>
                </label>
                <label v-else class="mode-field checkout-rule-hint">
                  <span>{{ checkoutRuleHint(rule) }}</span>
                  <small>{{ checkoutRuleDescription(entry.mode, rule) }}</small>
                </label>
              </div>

              <footer class="rule-card__footer">
                <el-checkbox
                  v-if="rule.type !== 'suggest_category'"
                  v-model="rule.required"
                >
                  {{ $t('Must be confirmed before checkout') }}
                </el-checkbox>
                <span v-else>{{ $t('Optional suggestion. It never adds a product automatically') }}</span>
              </footer>
            </article>
          </section>
          </div>
        </article>
      </el-tab-pane>
    </el-tabs>

    <el-alert
      v-if="validationErrors.length"
      :title="$t('Complete the dining mode settings before saving')"
      type="warning"
      :closable="false"
      show-icon
    >
      <ul class="validation-list">
        <li v-for="(error, index) in validationErrors" :key="`${error.key}-${index}`">
          <strong>{{ validationContext(error) }}</strong>{{ $t(error.key) }}
        </li>
      </ul>
    </el-alert>
  </section>
</template>

<script>
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { t } from '@/i18n'
import {
  changeCheckoutRuleType,
  changeDiningRuleType,
  createCheckoutRule,
  createDiningMode,
  createDiningModesConfig,
  createDiningRule,
  createTimeWindow,
  normalizeDiningModesConfig,
  validateDiningModesConfig
} from './dining-modes.mjs'

const categoryEndpoint = apiPath(API_PREFIX, 'manage/product-categories')
const productEndpoint = apiPath(API_PREFIX, 'manage/products')

export default {
  name: 'DiningModesEditor',
  props: {
    form: { type: Object, required: true },
    property: { type: String, required: true },
    storeScopeUuid: { type: String, default: '' }
  },
  data() {
    return {
      categories: [],
      products: [],
      loading: false,
      loadError: '',
      activeModeUuid: '',
      weekdays: [
        { value: 1, label: 'Monday' },
        { value: 2, label: 'Tuesday' },
        { value: 3, label: 'Wednesday' },
        { value: 4, label: 'Thursday' },
        { value: 5, label: 'Friday' },
        { value: 6, label: 'Saturday' },
        { value: 7, label: 'Sunday' }
      ]
    }
  },
  computed: {
    config() {
      return this.form[this.property] || createDiningModesConfig()
    },
    modes() {
      return Array.isArray(this.config.modes) ? this.config.modes : []
    },
    sortedModeEntries() {
      return this.modes
        .map((mode, index) => ({ mode, index }))
        .sort((left, right) => right.mode.priority - left.mode.priority || left.index - right.index)
    },
    storeUuid() {
      return this.storeScopeUuid || this.findStoreForm()?.form?.uuid || ''
    },
    categoryOptions() {
      return this.categories.filter(category => this.inCurrentStoreScope(category))
    },
    productOptions() {
      return this.products.filter(product => !product.isDeleted && this.inCurrentStoreScope(product))
    },
    specificationOptions() {
      const products = new Map(this.productOptions.map(product => [product.uuid, product]))
      const rows = []
      for (const product of products.values()) {
        for (const specification of Array.isArray(product.specifications) ? product.specifications : []) {
          if (!specification?.uuid || specification.isDeleted) continue
          const productName = product.name || product.__toString || product.uuid
          const specificationName = specification.name || specification.__toString || specification.uuid
          const isGlobal = !product.store?.uuid
          rows.push({
            uuid: specification.uuid,
            productUuid: product.uuid,
            productName,
            specificationName,
            isGlobal,
            label: `${productName} / ${specificationName}${isGlobal ? ` · ${t('Global')}` : ''}`,
            searchLabel: `${productName} ${specificationName}`
          })
        }
      }
      return rows.sort((left, right) => left.label.localeCompare(right.label))
    },
    specificationGroups() {
      const groups = new Map()
      for (const specification of this.specificationOptions) {
        if (!groups.has(specification.productUuid)) {
          groups.set(specification.productUuid, {
            uuid: specification.productUuid,
            label: `${specification.productName}${specification.isGlobal ? ` · ${t('Global')}` : ''}`,
            specifications: []
          })
        }
        groups.get(specification.productUuid).specifications.push(specification)
      }
      return [...groups.values()]
        .map(group => ({
          ...group,
          specifications: group.specifications.sort((left, right) => (
            left.specificationName.localeCompare(right.specificationName)
          ))
        }))
        .sort((left, right) => left.label.localeCompare(right.label))
    },
    specificationByUuid() {
      return new Map(this.specificationOptions.map(specification => [specification.uuid, specification]))
    },
    validationErrors() {
      return validateDiningModesConfig(this.config)
    }
  },
  created() {
    this.form[this.property] = normalizeDiningModesConfig(this.form[this.property])
    this.syncActiveMode()
    this.loadCatalog()
  },
  methods: {
    syncActiveMode() {
      const modeUuids = this.sortedModeEntries.map(entry => entry.mode.uuid)
      if (!modeUuids.includes(this.activeModeUuid)) this.activeModeUuid = modeUuids[0] || ''
      return this.activeModeUuid
    },
    findStoreForm() {
      let parent = this.$parent
      while (parent) {
        if (parent.$options?.name === 'FormAdmin' && parent.em?.name === 'Store') return parent
        parent = parent.$parent
      }
      return null
    },
    inCurrentStoreScope(record) {
      const scopeUuid = record?.store?.uuid
      if (!scopeUuid) return true
      return Boolean(this.storeUuid) && scopeUuid === this.storeUuid
    },
    categoryLabel(category) {
      return `${category.name || category.__toString || category.uuid}${category.store?.uuid ? '' : ` · ${t('Global')}`}`
    },
    productLabel(product) {
      return `${product.name || product.__toString || product.uuid}${product.store?.uuid ? '' : ` · ${t('Global')}`}`
    },
    persist(config = this.config) {
      this.form[this.property] = normalizeDiningModesConfig(config)
    },
    async loadCatalog() {
      if (this.loading) return
      this.loading = true
      this.loadError = ''
      try {
        const [categoryResponse, productResponse] = await Promise.all([
          request.get(categoryEndpoint, { params: { limit: 500 }}),
          request.get(productEndpoint, { params: { limit: 500, '@expands': 'specifications' }})
        ])
        this.categories = Array.isArray(categoryResponse.data) ? categoryResponse.data : []
        this.products = Array.isArray(productResponse.data) ? productResponse.data : []
      } catch (cause) {
        this.loadError = cause.response?.data?.message || cause.message || t('Failed to load dining mode products')
      } finally {
        this.loading = false
      }
    },
    addMode() {
      const priorities = this.modes.map(mode => Number(mode.priority) || 0)
      const mode = createDiningMode({ priority: (priorities.length ? Math.max(...priorities) : 0) + 10 })
      this.config.modes.push(mode)
      this.activeModeUuid = mode.uuid
      this.persist()
    },
    async removeMode(mode) {
      if (!window.confirm(t('Delete mode and all its rules?'))) return
      const entries = this.sortedModeEntries
      const modeIndex = entries.findIndex(entry => entry.mode.uuid === mode.uuid)
      const nextModeUuid = entries[modeIndex + 1]?.mode.uuid || entries[modeIndex - 1]?.mode.uuid || ''
      const wasActive = this.activeModeUuid === mode.uuid
      this.config.modes = this.modes.filter(item => item.uuid !== mode.uuid)
      if (wasActive) this.activeModeUuid = nextModeUuid
      this.syncActiveMode()
      this.persist()
    },
    moveMode(mode, direction) {
      const entries = this.sortedModeEntries
      const index = entries.findIndex(entry => entry.mode.uuid === mode.uuid)
      const other = entries[index + direction]?.mode
      if (!other) return
      const priority = mode.priority
      mode.priority = other.priority === priority ? priority - direction : other.priority
      other.priority = priority
      this.persist()
    },
    addTimeWindow(mode) {
      mode.availability.timeWindows.push(createTimeWindow())
    },
    removeTimeWindow(mode, index) {
      mode.availability.timeWindows.splice(index, 1)
    },
    addRule(mode, type) {
      mode.rules.push(createDiningRule(type))
    },
    removeRule(mode, index) {
      mode.rules.splice(index, 1)
    },
    moveRule(mode, index, direction) {
      const target = index + direction
      if (target < 0 || target >= mode.rules.length) return
      const [rule] = mode.rules.splice(index, 1)
      mode.rules.splice(target, 0, rule)
    },
    changeRuleType(mode, index, type) {
      mode.rules[index] = changeDiningRuleType(mode.rules[index], type)
    },
    addCheckoutRule(mode, type) {
      mode.checkoutRules.push(createCheckoutRule(type))
    },
    removeCheckoutRule(mode, index) {
      mode.checkoutRules.splice(index, 1)
    },
    moveCheckoutRule(mode, index, direction) {
      const target = index + direction
      if (target < 0 || target >= mode.checkoutRules.length) return
      const [rule] = mode.checkoutRules.splice(index, 1)
      mode.checkoutRules.splice(target, 0, rule)
    },
    changeCheckoutRuleType(mode, index, type) {
      mode.checkoutRules[index] = changeCheckoutRuleType(mode.checkoutRules[index], type)
    },
    specificationFor(uuid) {
      return this.specificationByUuid.get(String(uuid || ''))
    },
    selectedSpecifications(uuids) {
      return (Array.isArray(uuids) ? uuids : [])
        .map(uuid => this.specificationFor(uuid))
        .filter(Boolean)
    },
    removeChoiceSpecification(rule, uuid) {
      rule.specificationUuids = rule.specificationUuids.filter(item => item !== uuid)
    },
    ruleDescription(rule) {
      if (rule.type === 'choice_group') {
        return t('Choose between {0} and {1}', rule.minSelections, rule.maxSelections)
      }
      return t(rule.type === 'per_guest_item' ? 'Calculated from guest count' : 'Added to the order by default')
    },
    checkoutRuleHint(rule) {
      if (rule.type === 'confirm_participants') return t('The checkout screen asks staff to confirm the current guest count')
      return t('The checkout screen checks required fixed and per-guest items against the order')
    },
    checkoutRuleDescription(mode, rule) {
      if (rule.type === 'confirm_participants') {
        const guestRules = mode.rules.filter(item => item.type === 'per_guest_item')
        return guestRules.length
          ? t('{0} per-guest ordering rules use this count', guestRules.length)
          : t('The session guest count is only displayed and checked')
      }
      const requiredRules = mode.rules.filter(item => (
        ['fixed_item', 'per_guest_item'].includes(item.type) && item.required
      ))
      return t('{0} required ordering rules are included in this review', requiredRules.length)
    },
    modeSummary(mode) {
      const windows = mode.availability?.allDay
        ? t('All day')
        : (mode.availability?.timeWindows || []).map(window => `${window.start}-${window.end}`).join(', ') || t('No sales time')
      return `${windows} · ${mode.rules?.length || 0} ${t('rules')} · ${mode.checkoutRules?.length || 0} ${t('checkout rules')}`
    },
    validationContext(error) {
      const mode = this.modes.find(item => item.uuid === error.modeUuid)
      const modeLabel = mode?.name || t('Unnamed dining mode')
      if (error.checkoutRuleIndex !== undefined) {
        const rule = mode?.checkoutRules?.[error.checkoutRuleIndex]
        return `${modeLabel} / ${rule?.name || t('Checkout rule')} ${error.checkoutRuleIndex + 1}: `
      }
      if (error.ruleIndex === undefined) return `${modeLabel}: `
      const rule = mode?.rules?.[error.ruleIndex]
      return `${modeLabel} / ${rule?.name || t('Rule')} ${error.ruleIndex + 1}: `
    }
  }
}
</script>

<style scoped>
.dining-modes {
  display: grid;
  gap: 16px;
  width: 100%;
}
.dining-modes__header,
.mode-card__header,
.mode-section__title,
.rule-card__header,
.rule-card__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.dining-modes__header p,
.mode-section__title p,
.mode-note,
.mode-field small {
  margin: 4px 0 0;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.5;
}
.dining-modes__tabs :deep(.el-tabs__header) {
  margin-bottom: 0;
}
.dining-modes__tabs :deep(.el-tabs__content) {
  padding: 0;
}
.dining-modes__tabs--single :deep(.el-tabs__header) {
  display: none;
}
.mode-tab-label {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.mode-tab-label__name {
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
}
.mode-tab-label__meta {
  color: var(--el-text-color-secondary);
  font-size: 11px;
  font-weight: 500;
}
.mode-tab-label__disabled {
  padding: 1px 5px;
  border-radius: 999px;
  background: var(--el-fill-color-dark);
  color: var(--el-text-color-secondary);
  font-size: 10px;
}
.mode-card {
  overflow: hidden;
  border: 0;
  border-radius: 0;
  background: var(--el-bg-color);
}
.mode-card__header {
  align-items: flex-start;
  padding: 14px 16px;
  background: var(--el-fill-color-lighter);
}
.mode-card__heading {
  display: grid;
  min-width: 0;
  flex: 1;
  gap: 5px;
}
.mode-card__title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  font-size: 15px;
}
.mode-card__summary {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
.mode-card__actions,
.rule-actions,
.rule-card__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}
.mode-card__body {
  display: grid;
  gap: 24px;
  padding: 4px 16px 20px;
}
.mode-section {
  display: grid;
  gap: 12px;
  padding-top: 16px;
  border-top: 1px solid var(--el-border-color-lighter);
}
.mode-section h4,
.mode-section p {
  margin: 0;
}
.mode-grid,
.rule-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.mode-grid--three,
.rule-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.mode-field {
  display: grid;
  align-content: start;
  gap: 6px;
  min-width: 0;
  color: var(--el-text-color-regular);
  font-size: 13px;
}
.mode-field--wide {
  grid-column: span 2;
}
.mode-field :deep(.el-select),
.mode-field :deep(.el-input-number) {
  width: 100%;
}
.weekday-group {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 16px;
}
.time-window {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.time-window :deep(.el-date-editor) {
  width: 128px;
}
.empty-inline {
  padding: 12px 14px;
  border-radius: 8px;
  background: var(--el-fill-color-lighter);
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
.rule-card {
  position: relative;
  display: grid;
  gap: 14px;
  padding: 16px 16px 0;
  overflow: hidden;
  border: 1px solid var(--el-border-color);
  border-radius: 10px;
  background: var(--el-fill-color-blank);
  box-shadow: 0 2px 8px rgb(0 0 0 / 4%);
}
.rule-card__header {
  align-items: flex-start;
  flex-wrap: wrap;
  padding-bottom: 13px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}
.rule-card__index {
  display: inline-grid;
  width: 30px;
  height: 30px;
  flex: 0 0 30px;
  place-items: center;
  border-radius: 9px;
  background: var(--el-color-primary);
  color: var(--el-color-white);
  font-size: 14px;
  font-weight: 700;
  line-height: 1;
}
.rule-type {
  width: 210px;
}
.rule-name {
  min-width: 160px;
  flex: 1;
}
.rule-body,
.rule-card__footer {
  display: grid;
  gap: 12px;
}
.rule-card__footer {
  grid-template-columns: auto auto 1fr;
  align-items: center;
  margin: 0 -16px;
  padding: 11px 16px;
  border-top: 1px solid var(--el-border-color-lighter);
  background: var(--el-fill-color-lighter);
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
.spec-picker {
  display: grid;
  gap: 8px;
}
.spec-picker :deep(.el-select) {
  width: 100%;
}
.spec-picker__help {
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.5;
}
.spec-picker__selected {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 10px;
  border: 1px dashed var(--el-color-primary-light-5);
  border-radius: 8px;
  background: var(--el-color-primary-light-9);
}
.spec-chip {
  display: flex;
  min-width: 210px;
  max-width: 100%;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--el-color-primary-light-7);
  border-radius: 7px;
  background: var(--el-bg-color);
}
.spec-chip__main {
  display: grid;
  min-width: 0;
  flex: 1;
  gap: 2px;
}
.spec-chip__main strong,
.spec-chip__main span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.spec-chip__main strong {
  color: var(--el-color-primary);
  font-size: 11px;
  font-weight: 600;
}
.spec-chip__main span {
  color: var(--el-text-color-primary);
  font-size: 13px;
  font-weight: 600;
}
.spec-chip__scope,
.spec-option__scope {
  padding: 1px 6px;
  border-radius: 999px;
  background: var(--el-fill-color);
  color: var(--el-text-color-secondary);
  font-size: 11px;
  white-space: nowrap;
}
.spec-chip__remove {
  width: 22px;
  height: 22px;
  flex: 0 0 22px;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: var(--el-text-color-secondary);
  font-size: 18px;
  line-height: 20px;
  cursor: pointer;
}
.spec-chip__remove:hover {
  background: var(--el-color-danger-light-9);
  color: var(--el-color-danger);
}
.spec-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.spec-option__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.validation-list {
  display: grid;
  gap: 4px;
  margin: 8px 0 0;
  padding-left: 18px;
}
@media (max-width: 900px) {
  .mode-card__header,
  .mode-section__title,
  .rule-card__header,
  .rule-card__footer {
    align-items: stretch;
    flex-direction: column;
  }
  .mode-card__actions,
  .rule-actions {
    justify-content: flex-start;
  }
  .mode-grid,
  .mode-grid--three,
  .rule-grid,
  .rule-card__footer {
    grid-template-columns: minmax(0, 1fr);
  }
  .mode-field--wide {
    grid-column: auto;
  }
  .rule-type {
    width: 100%;
  }
}
</style>
