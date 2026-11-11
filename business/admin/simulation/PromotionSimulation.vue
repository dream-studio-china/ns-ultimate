<template>
  <section class="promotion-simulation" aria-labelledby="promotion-simulation-title">
    <header class="promotion-simulation__header">
      <div>
        <span class="promotion-simulation__eyebrow">{{ $t('Promotion Management') }}</span>
        <h1 id="promotion-simulation-title">{{ $t('Promotion simulation') }}</h1>
        <p>{{ $t('Promotion simulation help') }}</p>
      </div>
      <div class="promotion-simulation__actions">
        <el-button :loading="running" type="primary" :icon="icons.VideoPlay" @click="run">{{ $t('Run simulation') }}</el-button>
        <el-button :icon="icons.Download" :disabled="!result" @click="exportResult">{{ $t('Export report') }}</el-button>
      </div>
    </header>

    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="promotion-simulation__alert" />
    <el-alert v-if="notice" :title="notice" type="success" :closable="true" show-icon class="promotion-simulation__alert" @close="notice = ''" />

    <section class="promotion-simulation__toolbar">
      <el-input v-model="scenario.name" class="promotion-simulation__name" :aria-label="$t('Scenario name')" :placeholder="$t('Scenario name')" />
      <div class="promotion-simulation__import-group">
        <el-input v-model="scenario.storeCode" class="promotion-simulation__store" :aria-label="$t('Store code')" :placeholder="$t('Store code for import')" />
        <el-button :loading="importingCurrent" :icon="icons.Refresh" @click="importCurrent">{{ $t('Import current campaigns') }}</el-button>
      </div>
      <div class="promotion-simulation__import-group">
        <el-input v-model="orderId" class="promotion-simulation__short" :aria-label="$t('Order ID')" :placeholder="$t('Order ID')" />
        <el-button :loading="importingOrder" :icon="icons.Tickets" @click="importOrder">{{ $t('Import order') }}</el-button>
      </div>
      <div class="promotion-simulation__actions">
        <el-button :icon="icons.Upload" @click="openImport">{{ $t('Import scenario') }}</el-button>
        <el-button :icon="icons.Download" @click="exportScenarioFile">{{ $t('Export scenario') }}</el-button>
      </div>
    </section>
    <small class="promotion-simulation__note">{{ $t('Scenario data is kept in this browser only. Simulation is read-only.') }}</small>

    <el-tabs v-model="activeWorldKey" class="promotion-simulation__worlds" @tab-click="selectWorld">
      <el-tab-pane v-for="world in scenario.worlds" :key="world.key" :name="world.key">
        <template #label>
          <span>{{ world.name }} <el-tag v-if="world.source === 'import'" size="small" effect="plain">{{ $t('Imported snapshot') }}</el-tag></span>
        </template>
      </el-tab-pane>
    </el-tabs>
    <div class="promotion-simulation__world-actions">
      <el-button size="small" :icon="icons.Plus" @click="addWorld">{{ $t('Add experiment world') }}</el-button>
      <el-button size="small" :icon="icons.Remove" :disabled="scenario.worlds.length <= 1" @click="removeWorld">{{ $t('Remove world') }}</el-button>
      <span v-if="activeWorld?.source === 'import'" class="promotion-simulation__revision">
        {{ $t('Imported at {time}', { time: formatDate(activeWorld.loadedAt) }) }} · {{ activeWorld.revision?.slice(0, 12) }}
      </span>
    </div>

    <section v-if="activeWorld" class="promotion-simulation__panel">
      <header class="promotion-simulation__panel-header">
        <h2>{{ $t('Campaign set') }} · {{ activeWorld.name }}</h2>
        <el-button size="small" type="primary" :icon="icons.Plus" @click="addCampaign">{{ $t('Add campaign') }}</el-button>
      </header>
      <el-table :data="activeWorld.campaigns" row-key="key" :empty-text="$t('No campaigns in this world')">
        <el-table-column prop="name" :label="$t('Campaign')" min-width="180" show-overflow-tooltip />
        <el-table-column :label="$t('Template type')" min-width="150">
          <template #default="{ row }">{{ row.template?.type }} · {{ row.template?.phase === 1 ? $t('Outer promotion phase') : $t('Inner promotion phase') }}</template>
        </el-table-column>
        <el-table-column :label="$t('Store code')" min-width="120"><template #default="{ row }">{{ row.storeCode || $t('Global') }}</template></el-table-column>
        <el-table-column :label="$t('Conflict mode')" min-width="120"><template #default="{ row }">{{ $t(conflictModeLabel(row.conflictMode)) }}</template></el-table-column>
        <el-table-column :label="$t('Campaign enabled')" width="130">
          <template #default="{ row }"><el-switch v-model="row.enabled" /></template>
        </el-table-column>
        <el-table-column :label="$t('Template enabled')" width="130">
          <template #default="{ row }"><el-switch v-model="row.templateEnabled" /></template>
        </el-table-column>
        <el-table-column :label="$t('Actions')" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="editCampaign(row)">{{ $t('Edit') }}</el-button>
            <el-button link type="danger" @click="removeCampaign(row)">{{ $t('Delete') }}</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section class="promotion-simulation__panel">
      <header class="promotion-simulation__panel-header">
        <h2>{{ $t('Test carts') }}</h2>
        <div class="promotion-simulation__case-actions">
          <el-input-number v-model="boundaryThreshold" :min="0" :precision="2" :controls="false" :aria-label="$t('Threshold in yuan')" :placeholder="$t('Threshold in yuan')" />
          <el-button size="small" @click="generateBoundaryCases">{{ $t('Generate boundary cases') }}</el-button>
          <el-button size="small" type="primary" :icon="icons.Plus" @click="addCase">{{ $t('Add cart case') }}</el-button>
        </div>
      </header>
      <el-table :data="scenario.cases" row-key="key" :empty-text="$t('No cart cases')">
        <el-table-column prop="name" :label="$t('Case')" min-width="160" show-overflow-tooltip />
        <el-table-column :label="$t('Store code')" min-width="110"><template #default="{ row }">{{ row.storeCode || $t('Global') }}</template></el-table-column>
        <el-table-column :label="$t('Simulated at')" min-width="170"><template #default="{ row }">{{ formatDate(row.simulatedAt) }}</template></el-table-column>
        <el-table-column :label="$t('Pricing mode')" min-width="130"><template #default="{ row }">{{ $t(row.pricingMode === 'current_catalog' ? 'Current catalog price' : 'Snapshot price') }}</template></el-table-column>
        <el-table-column :label="$t('Items')" width="80"><template #default="{ row }">{{ row.items.length }}</template></el-table-column>
        <el-table-column :label="$t('User')" min-width="110"><template #default="{ row }">{{ userLabel(row) }}</template></el-table-column>
        <el-table-column :label="$t('Expected')" min-width="150"><template #default="{ row }">{{ expectedLabel(row.expected) }}</template></el-table-column>
        <el-table-column :label="$t('Actions')" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="editCase(row)">{{ $t('Edit') }}</el-button>
            <el-button link type="danger" @click="removeCase(row)">{{ $t('Delete') }}</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section v-if="result" class="promotion-simulation__panel">
      <header class="promotion-simulation__panel-header">
        <h2>{{ $t('Result matrix') }}</h2>
        <span class="promotion-simulation__summary">
          {{ $t('Passed') }} {{ result.summary.passed }} · {{ $t('Failed') }} {{ result.summary.failed }} · {{ $t('Errors') }} {{ result.summary.errors }}
        </span>
      </header>
      <el-alert v-if="worldErrors.length" type="warning" :closable="false" show-icon :title="$t('Some campaign snapshots were excluded from execution: {errors}', { errors: worldErrors.join('; ') })" />
      <el-table :data="matrix" border>
        <el-table-column :label="$t('Case')" min-width="170" fixed>
          <template #default="{ row }">{{ row.case.name }}</template>
        </el-table-column>
        <el-table-column v-for="world in result.worlds" :key="world.key" :label="world.name" min-width="190">
          <template #default="{ row }">
            <button class="promotion-simulation__cell" type="button" @click="selectCell(world.key, row.case.key)">
              <template v-if="row.cells.find(cell => cell.world.key === world.key)?.result">
                <span v-if="row.cells.find(cell => cell.world.key === world.key).result.status === 'error'" class="promotion-simulation__cell-error">
                  {{ $t('Error') }}
                </span>
                <template v-else>
                  <strong>{{ formatMinor(row.cells.find(cell => cell.world.key === world.key).result.afterAmount, row.case.currency) }}</strong>
                  <el-tag :type="resultTagType(row.cells.find(cell => cell.world.key === world.key).result)" size="small" effect="plain">
                    {{ resultLabel(row.cells.find(cell => cell.world.key === world.key).result) }}
                  </el-tag>
                </template>
              </template>
              <span v-else class="promotion-simulation__muted">{{ $t('Run to see result') }}</span>
            </button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section v-if="selectedResult" class="promotion-simulation__panel promotion-simulation__detail">
      <header class="promotion-simulation__panel-header">
        <h2>{{ $t('Case detail') }} · {{ selectedCase?.name }}</h2>
        <el-button size="small" @click="selectedResult = null">{{ $t('Close') }}</el-button>
      </header>
      <el-alert v-if="selectedResult.status === 'error'" :title="selectedResult.error" type="error" :closable="false" show-icon />
      <template v-else>
        <div class="promotion-simulation__totals">
          <span>{{ $t('Before') }} <strong>{{ formatMinor(selectedResult.beforeAmount, selectedCase?.currency) }}</strong></span>
          <span>{{ $t('After') }} <strong>{{ formatMinor(selectedResult.afterAmount, selectedCase?.currency) }}</strong></span>
          <span>{{ $t('Discount') }} <strong>{{ formatMinor(selectedResult.discountAmount, selectedCase?.currency) }}</strong></span>
          <span v-if="selectedResult.freeShipping"><el-tag type="success" effect="plain">{{ $t('Free shipping') }}</el-tag></span>
          <span v-if="selectedResult.itemSummary?.giftQuantity">{{ $t('Gifts: {count}', { count: selectedResult.itemSummary.giftQuantity }) }}</span>
        </div>
        <h3>{{ $t('Applied promotions') }}</h3>
        <el-table :data="selectedResult.applied" size="small" :empty-text="$t('No promotion applied')">
          <el-table-column prop="name" :label="$t('Campaign')" min-width="160" />
          <el-table-column prop="phase" :label="$t('Promotion calculation phase')" width="120" />
          <el-table-column prop="conflictMode" :label="$t('Conflict mode')" width="130" />
          <el-table-column :label="$t('After')" width="130"><template #default="{ row }">{{ formatMinor(row.totalAfter, selectedCase?.currency) }}</template></el-table-column>
          <el-table-column :label="$t('Discount')" width="130"><template #default="{ row }">{{ formatMinor(row.changedAmount, selectedCase?.currency) }}</template></el-table-column>
        </el-table>
        <p v-if="selectedResult.bestPriceKeys?.length" class="promotion-simulation__muted">{{ $t('Best-price candidates') }}: {{ selectedResult.bestPriceKeys.join(', ') }}</p>
        <h3>{{ $t('Diagnostics') }}</h3>
        <el-table :data="selectedResult.diagnostics" size="small">
          <el-table-column prop="campaignKey" :label="$t('Campaign key')" min-width="130" />
          <el-table-column prop="name" :label="$t('Campaign')" min-width="160" />
          <el-table-column :label="$t('Status')" min-width="150"><template #default="{ row }"><el-tag size="small" effect="plain">{{ row.status }}</el-tag></template></el-table-column>
          <el-table-column prop="message" :label="$t('Reason')" min-width="280" show-overflow-tooltip />
        </el-table>
        <h3>{{ $t('Final item amounts') }}</h3>
        <p class="promotion-simulation__note">{{ $t('Item prices reflect item-level actions. Order-level reductions affect only the order total.') }}</p>
        <el-table :data="selectedResult.items" size="small">
          <el-table-column prop="specificationId" :label="$t('Specification')" min-width="120" />
          <el-table-column prop="name" :label="$t('Name')" min-width="160" />
          <el-table-column prop="quantity" :label="$t('Quantity')" width="90" />
          <el-table-column :label="$t('Unit price')" width="130"><template #default="{ row }">{{ formatMinor(row.unitPrice, selectedCase?.currency) }}</template></el-table-column>
          <el-table-column :label="$t('Amount')" width="130"><template #default="{ row }">{{ formatMinor(row.price, selectedCase?.currency) }}</template></el-table-column>
          <el-table-column :label="$t('Gift')" width="80"><template #default="{ row }">{{ row.isGift ? $t('Yes') : '' }}</template></el-table-column>
        </el-table>
        <template v-if="selectedResult.differences?.length">
          <h3>{{ $t('Expected differences') }}</h3>
          <el-table :data="selectedResult.differences" size="small">
            <el-table-column prop="path" :label="$t('Field')" width="180" />
            <el-table-column :label="$t('Expected')" min-width="180"><template #default="{ row }">{{ displayValue(row.expected) }}</template></el-table-column>
            <el-table-column :label="$t('Actual')" min-width="180"><template #default="{ row }">{{ displayValue(row.actual) }}</template></el-table-column>
          </el-table>
        </template>
        <p class="promotion-simulation__note">{{ $t('Promotion-only snapshot. Catalog, tax, shipping fees, payment and concurrency are not verified.') }}</p>
      </template>
    </section>

    <el-dialog v-model="campaignDialog" class="promotion-simulation__dialog" :title="campaignDraft.isNew ? $t('Add campaign') : $t('Edit campaign')" width="min(760px, 94vw)" destroy-on-close>
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="promotion-simulation__alert" />
      <el-form label-position="top">
        <div class="promotion-simulation__form-grid">
          <el-form-item :label="$t('Campaign key')"><el-input v-model="campaignDraft.key" :disabled="!campaignDraft.isNew" /></el-form-item>
          <el-form-item :label="$t('Campaign')"><el-input v-model="campaignDraft.name" /></el-form-item>
          <el-form-item :label="$t('Store code')"><el-input v-model="campaignDraft.storeCode" :placeholder="$t('Empty means global')" /></el-form-item>
          <el-form-item :label="$t('Conflict mode')">
            <el-select v-model="campaignDraft.conflictMode" popper-class="promotion-simulation__popper"><el-option v-for="mode in conflictModes" :key="mode" :label="$t(conflictModeLabel(mode))" :value="mode" /></el-select>
          </el-form-item>
          <el-form-item :label="$t('Start time')"><el-date-picker v-model="campaignDraft.startTime" popper-class="promotion-simulation__popper" type="datetime" value-format="YYYY-MM-DDTHH:mm:ss" clearable /></el-form-item>
          <el-form-item :label="$t('End time')"><el-date-picker v-model="campaignDraft.endTime" popper-class="promotion-simulation__popper" type="datetime" value-format="YYYY-MM-DDTHH:mm:ss" clearable /></el-form-item>
          <el-form-item :label="$t('Campaign enabled')"><el-switch v-model="campaignDraft.enabled" /></el-form-item>
          <el-form-item :label="$t('Template enabled')"><el-switch v-model="campaignDraft.templateEnabled" /></el-form-item>
          <el-form-item :label="$t('Template type')">
            <el-select v-model="campaignDraft.template.type" popper-class="promotion-simulation__popper">
              <el-option v-for="type in templateTypes" :key="type" :label="type" :value="type" />
            </el-select>
          </el-form-item>
          <el-form-item :label="$t('Promotion calculation phase')">
            <el-select v-model="campaignDraft.template.phase" popper-class="promotion-simulation__popper"><el-option :label="$t('Inner promotion phase')" :value="0" /><el-option :label="$t('Outer promotion phase')" :value="1" /></el-select>
          </el-form-item>
        </div>
        <el-form-item :label="$t('Promotion DSL')"><el-input v-model="campaignDraft.template.dsl" type="textarea" :rows="8" /></el-form-item>
        <el-form-item :label="$t('Promotion config')"><el-input v-model="campaignConfigText" type="textarea" :rows="7" spellcheck="false" /></el-form-item>
      </el-form>
      <template #footer><el-button @click="campaignDialog = false">{{ $t('Cancel') }}</el-button><el-button type="primary" @click="saveCampaign">{{ $t('Save to scenario') }}</el-button></template>
    </el-dialog>

    <el-dialog v-model="caseDialog" class="promotion-simulation__dialog" :title="caseDraft.isNew ? $t('Add cart case') : $t('Edit cart case')" width="min(900px, 96vw)" destroy-on-close>
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="promotion-simulation__alert" />
      <el-form label-position="top">
        <div class="promotion-simulation__form-grid">
          <el-form-item :label="$t('Case key')"><el-input v-model="caseDraft.key" :disabled="!caseDraft.isNew" /></el-form-item>
          <el-form-item :label="$t('Case')"><el-input v-model="caseDraft.name" /></el-form-item>
          <el-form-item :label="$t('Store code')"><el-input v-model="caseDraft.storeCode" :placeholder="$t('Empty means global')" /></el-form-item>
          <el-form-item :label="$t('Simulated at')"><el-date-picker v-model="caseDraft.simulatedAt" popper-class="promotion-simulation__popper" type="datetime" value-format="YYYY-MM-DDTHH:mm:ss" /></el-form-item>
          <el-form-item :label="$t('Currency')"><el-input v-model="caseDraft.currency" maxlength="3" /></el-form-item>
          <el-form-item :label="$t('Pricing mode')">
            <el-select v-model="caseDraft.pricingMode" popper-class="promotion-simulation__popper">
              <el-option :label="$t('Snapshot price')" value="snapshot" />
              <el-option :label="$t('Current catalog price')" value="current_catalog" />
            </el-select>
          </el-form-item>
          <el-form-item :label="$t('User mode')">
            <el-select v-model="caseDraft.user.mode" popper-class="promotion-simulation__popper">
              <el-option :label="$t('Anonymous')" value="anonymous" />
              <el-option :label="$t('Member level')" value="level" />
              <el-option :label="$t('Existing user')" value="user" />
            </el-select>
          </el-form-item>
          <el-form-item v-if="caseDraft.user.mode === 'level'" :label="$t('Member level')"><el-select v-model="caseDraft.user.level" popper-class="promotion-simulation__popper"><el-option v-for="level in memberLevels" :key="level" :label="level" :value="level" /></el-select></el-form-item>
          <el-form-item v-if="caseDraft.user.mode === 'user'" :label="$t('User ID')">
            <div class="promotion-simulation__inline"><el-input v-model.number="caseDraft.user.userId" /><el-button @click="loadUser">{{ $t('Load user') }}</el-button></div>
          </el-form-item>
        </div>
        <h3>{{ $t('Items') }}</h3>
        <el-table :data="caseDraft.items" size="small">
          <el-table-column :label="$t('Specification ID')" width="160"><template #default="{ row }"><el-input v-model="row.specificationId" /></template></el-table-column>
          <el-table-column :label="$t('Name')" min-width="150"><template #default="{ row }"><el-input v-model="row.name" /></template></el-table-column>
          <el-table-column :label="$t('Quantity')" width="110"><template #default="{ row }"><el-input-number v-model="row.quantity" :min="1" :max="1000" :controls="false" /></template></el-table-column>
          <el-table-column :label="$t('Unit price (minor units)')" width="160"><template #default="{ row }"><el-input-number v-model="row.unitPrice" :min="0" :controls="false" :disabled="caseDraft.pricingMode === 'current_catalog'" /></template></el-table-column>
          <el-table-column width="80"><template #default="{ $index }"><el-button link type="danger" @click="caseDraft.items.splice($index, 1)">{{ $t('Delete') }}</el-button></template></el-table-column>
        </el-table>
        <el-button size="small" class="promotion-simulation__add-row" @click="caseDraft.items.push(newItem())">{{ $t('Add item') }}</el-button>
        <h3>{{ $t('Expected result') }}</h3>
        <div class="promotion-simulation__form-grid">
          <el-form-item :label="$t('Expected total (minor units)')"><el-input-number v-model="caseDraft.expected.totalAmount" :min="0" :controls="false" /></el-form-item>
          <el-form-item :label="$t('Expected discount (minor units)')"><el-input-number v-model="caseDraft.expected.discountAmount" :min="0" :controls="false" /></el-form-item>
          <el-form-item :label="$t('Expected gift quantity')"><el-input-number v-model="caseDraft.expected.giftQuantity" :min="0" :controls="false" /></el-form-item>
          <el-form-item :label="$t('Expected free shipping')"><el-select v-model="caseDraft.expected.freeShipping" popper-class="promotion-simulation__popper" clearable><el-option :label="$t('Yes')" :value="true" /><el-option :label="$t('No')" :value="false" /></el-select></el-form-item>
          <el-form-item :label="$t('Expected applied campaign keys')"><el-input :model-value="caseDraft.expected.appliedCampaignKeys.join(', ')" @input="value => setAppliedKeys(caseDraft.expected, value)" /></el-form-item>
          <el-form-item :label="$t('Expected item amounts (JSON)')" class="promotion-simulation__full"><el-input v-model="expectedItemsText" type="textarea" :rows="2" spellcheck="false" /></el-form-item>
        </div>
        <h3>{{ $t('Expected by world') }}</h3>
        <el-table :data="worldExpectedRows" size="small">
          <el-table-column prop="name" :label="$t('World')" min-width="140" />
          <el-table-column :label="$t('Expected total')" width="140"><template #default="{ row }"><el-input-number v-model="caseDraft.expectedByWorld[row.key].totalAmount" :min="0" :controls="false" /></template></el-table-column>
          <el-table-column :label="$t('Expected discount')" width="140"><template #default="{ row }"><el-input-number v-model="caseDraft.expectedByWorld[row.key].discountAmount" :min="0" :controls="false" /></template></el-table-column>
          <el-table-column :label="$t('Expected gift quantity')" width="140"><template #default="{ row }"><el-input-number v-model="caseDraft.expectedByWorld[row.key].giftQuantity" :min="0" :controls="false" /></template></el-table-column>
          <el-table-column :label="$t('Expected free shipping')" width="150"><template #default="{ row }"><el-select v-model="caseDraft.expectedByWorld[row.key].freeShipping" popper-class="promotion-simulation__popper" clearable><el-option :label="$t('Yes')" :value="true" /><el-option :label="$t('No')" :value="false" /></el-select></template></el-table-column>
          <el-table-column :label="$t('Applied keys')" min-width="190"><template #default="{ row }"><el-input :model-value="caseDraft.expectedByWorld[row.key].appliedCampaignKeys.join(', ')" @input="value => setAppliedKeys(caseDraft.expectedByWorld[row.key], value)" /></template></el-table-column>
        </el-table>
      </el-form>
      <template #footer><el-button @click="caseDialog = false">{{ $t('Cancel') }}</el-button><el-button type="primary" @click="saveCase">{{ $t('Save to scenario') }}</el-button></template>
    </el-dialog>

    <el-dialog v-model="importDialog" class="promotion-simulation__dialog" :title="$t('Import scenario')" width="min(720px, 94vw)">
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon class="promotion-simulation__alert" />
      <p>{{ $t('Paste a scenario JSON export. Importing replaces the current in-browser scenario, not live promotions.') }}</p>
      <el-input v-model="importText" type="textarea" :rows="14" spellcheck="false" />
      <template #footer><el-button @click="importDialog = false">{{ $t('Cancel') }}</el-button><el-button type="primary" @click="applyImport">{{ $t('Import') }}</el-button></template>
    </el-dialog>
  </section>
</template>

<script>
import './theme.css'
import { Download, Plus, Refresh, Remove, Tickets, Upload, VideoPlay } from '@element-plus/icons-vue'
import request from '@/utils/request'
import {
  CONFLICT_MODES, MEMBER_LEVELS, boundaryCases, buildMatrix, buildRunRequest,
  cloneWorldAs, createScenario, emptyExpected, formatMinor, hasExpected, importWorldSnapshot,
  localDateTime, newCampaign, newCase, newItem, newKey, normalizeScenario, scenarioExport,
  validateScenario
} from './scenario.mjs'

const STORAGE_KEY = 'ns-promotion-simulation-v1'
const TEMPLATE_TYPES = ['full_reduction', 'discount', 'member_discount', 'nth_discount', 'gift', 'free_shipping']

export default {
  name: 'PromotionSimulation',
  setup() {
    return { icons: { Download, Plus, Refresh, Remove, Tickets, Upload, VideoPlay } }
  },
  data() {
    return {
      scenario: createScenario(),
      activeWorldKey: 'baseline',
      result: null,
      selectedResult: null,
      error: '',
      notice: '',
      running: false,
      importingCurrent: false,
      importingOrder: false,
      orderId: '',
      boundaryThreshold: null,
      campaignDialog: false,
      campaignDraft: {},
      campaignConfigText: '{}',
      caseDialog: false,
      caseDraft: {},
      expectedItemsText: '',
      importDialog: false,
      importText: '',
      conflictModes: CONFLICT_MODES,
      memberLevels: MEMBER_LEVELS,
      templateTypes: TEMPLATE_TYPES
    }
  },
  computed: {
    activeWorld() { return this.scenario.worlds.find(world => world.key === this.activeWorldKey) || this.scenario.worlds[0] },
    matrix() { return this.result ? buildMatrix(this.result) : [] },
    selectedCase() { return this.result?.cases.find(item => item.key === this.selectedResult?.caseKey) || null },
    worldErrors() {
      return this.result?.worlds.flatMap(world => world.errors.map(error => `${world.name}: ${error.key}: ${error.message}`)) || []
    },
    worldExpectedRows() {
      if (!this.caseDraft.expectedByWorld) return []
      return this.scenario.worlds.map(world => ({ key: world.key, name: world.name }))
    }
  },
  watch: {
    scenario: { deep: true, handler() {
      this.persist()
      // Any input edit invalidates a previously displayed run. The user must
      // run again before treating the matrix as current evidence.
      if (this.result) { this.result = null; this.selectedResult = null }
    } }
  },
  mounted() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      if (raw) {
        this.scenario = normalizeScenario(JSON.parse(raw))
        this.activeWorldKey = this.scenario.worlds[0].key
      }
    } catch { this.notice = this.$t('Stored scenario was invalid and has been ignored.') }
  },
  methods: {
    newItem,
    formatMinor,
    conflictModeLabel(mode) {
      return { stackable: 'Stackable', exclusive: 'Exclusive', lock_item: 'Lock item', best_price: 'Best price' }[mode] || mode
    },
    resultLabel(result) {
      if (result.status === 'error') return this.$t('Error')
      if (result.passed === true) return this.$t('Verified')
      if (result.passed === false) return this.$t('Failed')
      return this.$t('Not verified')
    },
    resultTagType(result) {
      if (result.status === 'error' || result.passed === false) return 'danger'
      if (result.passed === true) return 'success'
      return 'info'
    },
    userLabel(row) {
      if (row.user.mode === 'level') return row.user.level
      if (row.user.mode === 'user') return `#${row.user.userId ?? ''}`
      return this.$t('Anonymous')
    },
    expectedLabel(expected) {
      if (!hasExpected(expected)) return this.$t('Not set')
      const parts = []
      if (Number.isSafeInteger(expected.totalAmount)) parts.push(this.formatMinor(expected.totalAmount))
      if (Number.isSafeInteger(expected.discountAmount)) parts.push(`-${this.formatMinor(expected.discountAmount)}`)
      if (expected.appliedCampaignKeys?.length) parts.push(expected.appliedCampaignKeys.join(','))
      return parts.join(' · ')
    },
    displayValue(value) { return typeof value === 'object' && value !== null ? JSON.stringify(value) : String(value ?? '—') },
    formatDate(value) {
      if (!value) return '—'
      const date = new Date(value)
      return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString()
    },
    selectWorld() {},
    persist() {
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(this.scenario)) } catch { /* Page memory only. */ }
    },
    async importCurrent() {
      this.importingCurrent = true
      this.error = ''
      try {
        const response = await request.get('/api/v1/manage/promotion-simulation/current', { params: { storeCode: this.scenario.storeCode || '' } })
        const world = importWorldSnapshot(response.data, 'baseline', this.$t('Imported current campaigns'))
        this.scenario.worlds[0] = world
        this.activeWorldKey = 'baseline'
        this.result = null
        this.notice = this.$t('Imported {count} campaigns as a fixed snapshot.', { count: world.campaigns.length })
      } catch (cause) { this.error = cause?.response?.data?.message || cause?.message || this.$t('Failed to import campaigns') } finally { this.importingCurrent = false }
    },
    async importOrder() {
      const id = String(this.orderId || '').trim()
      if (!/^\d+$/.test(id)) { this.error = this.$t('Enter an exact numeric order ID.'); return }
      this.importingOrder = true
      this.error = ''
      try {
        const response = await request.get(`/api/v1/manage/promotion-simulation/orders/${id}`)
        const data = response.data
        const item = newCase({
          key: newKey('case', this.scenario.cases.map(entry => entry.key)),
          name: `Order #${data.sourceOrderId}`,
          storeCode: data.storeCode || this.scenario.storeCode,
          simulatedAt: data.createdAt || localDateTime(),
          currency: data.currency,
          pricingMode: 'snapshot',
          items: data.items.map(line => ({
            specificationId: typeof line.specificationId === 'string' ? line.specificationId : (Number.isSafeInteger(line.specificationId) ? line.specificationId : ''),
            specificationUuid: line.specificationUuid || '',
            name: line.name || '',
            quantity: line.quantity,
            unitPrice: line.unitPrice
          })),
          user: data.userLevel ? { mode: 'level', level: data.userLevel } : { mode: 'anonymous' }
        }, this.scenario.cases.map(entry => entry.key))
        this.scenario.cases.push(item)
        this.notice = this.$t('Order snapshot imported as a cart case. Choose a world and run.')
      } catch (cause) { this.error = cause?.response?.data?.message || cause?.message || this.$t('Failed to import order') } finally { this.importingOrder = false }
    },
    addWorld() {
      if (this.scenario.worlds.length >= 4) { this.error = this.$t('At most four worlds are supported.'); return }
      const key = newKey('world', this.scenario.worlds.map(world => world.key))
      const world = cloneWorldAs(this.scenario.worlds[0], key, this.$t('Experiment') + ' ' + (this.scenario.worlds.length))
      this.scenario.worlds.push(world)
      this.activeWorldKey = key
    },
    removeWorld() {
      if (this.scenario.worlds.length <= 1) return
      this.scenario.worlds = this.scenario.worlds.filter(world => world.key !== this.activeWorldKey)
      this.activeWorldKey = this.scenario.worlds[0].key
      this.result = null
      this.selectedResult = null
    },
    setCampaign(key, worldKey = this.activeWorldKey) {
      const world = this.scenario.worlds.find(entry => entry.key === worldKey)
      if (!world) return
      world.campaigns = world.campaigns.filter(campaign => campaign.key !== key)
    },
    addCampaign() {
      this.error = ''
      const used = new Set(this.activeWorld.campaigns.map(campaign => campaign.key))
      let index = 1
      while (used.has(`campaign:${index}`)) index++
      const key = `campaign:${index}`
      this.campaignDraft = { ...newCampaign({ key, name: 'New campaign', storeCode: this.scenario.storeCode }), isNew: true }
      this.campaignConfigText = '{}'
      this.campaignDialog = true
    },
    editCampaign(campaign) {
      this.error = ''
      this.campaignDraft = { ...JSON.parse(JSON.stringify(campaign)), isNew: false }
      this.campaignConfigText = JSON.stringify(campaign.config || {}, null, 2)
      this.campaignDialog = true
    },
    saveCampaign() {
      this.error = ''
      try {
        const config = JSON.parse(this.campaignConfigText || '{}')
        if (config === null || typeof config !== 'object' || Array.isArray(config)) throw new TypeError(this.$t('Config must be a JSON object.'))
        const campaign = { ...this.campaignDraft, config }
        delete campaign.isNew
        campaign.startTime = campaign.startTime || null
        campaign.endTime = campaign.endTime || null
        const world = this.activeWorld
        const index = world.campaigns.findIndex(item => item.key === campaign.key)
        if (this.campaignDraft.isNew) {
          if (index >= 0) throw new TypeError(this.$t('Campaign key already exists.'))
          world.campaigns.push(campaign)
        } else world.campaigns.splice(index, 1, campaign)
        this.campaignDialog = false
        this.result = null
      } catch (cause) { this.error = cause.message || this.$t('Campaign configuration is invalid.') }
    },
    removeCampaign(campaign) {
      this.setCampaign(campaign.key)
      this.result = null
    },
    addCase() {
      this.error = ''
      const key = newKey('case', this.scenario.cases.map(item => item.key))
      this.caseDraft = { ...newCase({ key, name: 'Cart case', storeCode: this.scenario.storeCode, simulatedAt: localDateTime() }), isNew: true, expectedByWorld: this.ensureWorldExpected() }
      this.expectedItemsText = ''
      this.caseDialog = true
    },
    editCase(item) {
      this.error = ''
      this.caseDraft = { ...JSON.parse(JSON.stringify(item)), isNew: false, expectedByWorld: this.ensureWorldExpected(JSON.parse(JSON.stringify(item.expectedByWorld || {}))) }
      this.expectedItemsText = item.expected?.items?.length ? JSON.stringify(item.expected.items, null, 2) : ''
      this.caseDialog = true
    },
    ensureWorldExpected(source = {}) {
      const output = {}
      for (const world of this.scenario.worlds) output[world.key] = { ...emptyExpected(), ...(source[world.key] || {}) }
      return output
    },
    saveCase() {
      this.error = ''
      try {
        const item = { ...this.caseDraft }
        delete item.isNew
        item.expected = { ...item.expected }
        if (this.expectedItemsText.trim()) {
          const parsed = JSON.parse(this.expectedItemsText)
          if (!Array.isArray(parsed)) throw new TypeError(this.$t('Expected item amounts must be a JSON array.'))
          item.expected.items = parsed
        } else item.expected.items = []
        const index = this.scenario.cases.findIndex(entry => entry.key === item.key)
        if (this.caseDraft.isNew && index >= 0) throw new TypeError(this.$t('Case key already exists.'))
        if (item.items.length < 1) throw new TypeError(this.$t('Add at least one item.'))
        if (this.caseDraft.isNew) this.scenario.cases.push(item)
        else this.scenario.cases.splice(index, 1, item)
        this.caseDialog = false
        this.result = null
      } catch (cause) { this.error = cause.message || this.$t('Cart case is invalid.') }
    },
    removeCase(item) {
      if (this.scenario.cases.length <= 1) { this.error = this.$t('Keep at least one cart case.'); return }
      this.scenario.cases = this.scenario.cases.filter(entry => entry.key !== item.key)
      this.result = null
    },
    setAppliedKeys(expected, value) {
      expected.appliedCampaignKeys = String(value || '').split(',').map(item => item.trim()).filter(Boolean)
    },
    generateBoundaryCases() {
      if (!Number.isFinite(this.boundaryThreshold) || this.boundaryThreshold < 0) { this.error = this.$t('Enter a threshold amount in yuan.'); return }
      const base = this.scenario.cases[0]
      const cases = boundaryCases(base, Math.round(this.boundaryThreshold * 100))
      for (const item of cases) item.key = newKey('case', [...this.scenario.cases.map(entry => entry.key), ...cases.map(entry => entry.key)])
      this.scenario.cases.push(...cases)
      this.result = null
      this.notice = this.$t('Added three boundary cases. Set expected results before treating them as verification.')
    },
    async loadUser() {
      const id = Number(this.caseDraft.user.userId)
      if (!Number.isSafeInteger(id) || id < 1) { this.error = this.$t('Enter an exact numeric user ID.'); return }
      try {
        const response = await request.get(`/api/v1/manage/promotion-simulation/users/${id}`)
        this.caseDraft.user.level = response.data.level || 'bronze'
        this.notice = this.$t('User promotion context loaded. Only the level is shared with simulation.')
      } catch (cause) { this.error = cause?.response?.data?.message || cause?.message || this.$t('Failed to load user') }
    },
    async run() {
      this.error = ''
      this.notice = ''
      const errors = validateScenario(this.scenario)
      if (errors.length) { this.error = errors.join(' '); return }
      this.running = true
      try {
        const response = await request.post('/api/v1/manage/promotion-simulation/run', buildRunRequest(this.scenario), { timeout: 120000 })
        this.result = response.data
        this.selectedResult = null
        this.notice = this.$t('Simulation completed: {passed} passed, {failed} failed, {errors} errors.', this.result.summary)
      } catch (cause) { this.error = cause?.response?.data?.message || cause?.message || this.$t('Simulation failed') } finally { this.running = false }
    },
    selectCell(worldKey, caseKey) {
      this.selectedResult = this.result.results.find(result => result.worldKey === worldKey && result.caseKey === caseKey) || null
    },
    openImport() { this.error = ''; this.importText = ''; this.importDialog = true },
    applyImport() {
      try {
        this.scenario = normalizeScenario(JSON.parse(this.importText))
        this.activeWorldKey = this.scenario.worlds[0].key
        this.result = null
        this.importDialog = false
        this.notice = this.$t('Scenario imported into this browser workspace.')
      } catch (cause) { this.error = cause.message || this.$t('Scenario JSON is invalid.') }
    },
    download(name, content, type = 'application/json') {
      const link = document.createElement('a')
      link.href = URL.createObjectURL(new Blob([content], { type }))
      link.download = name
      link.click()
      URL.revokeObjectURL(link.href)
    },
    exportScenarioFile() { this.download('promotion-simulation-scenario.json', scenarioExport(this.scenario)) },
    exportResult() { if (this.result) this.download('promotion-simulation-report.json', JSON.stringify(this.result, null, 2)) }
  }
}
</script>

<style scoped>
.promotion-simulation { display: flex; flex-direction: column; gap: 16px; min-width: 0; padding: 20px; }
.promotion-simulation__header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
.promotion-simulation__eyebrow { color: var(--el-text-color-secondary); font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
.promotion-simulation h1 { margin: 4px 0 6px; font-size: 24px; }
.promotion-simulation h2 { margin: 0; font-size: 17px; }
.promotion-simulation h3 { margin: 18px 0 8px; font-size: 14px; }
.promotion-simulation p { margin: 0; color: var(--el-text-color-secondary); }
.promotion-simulation__actions, .promotion-simulation__toolbar, .promotion-simulation__world-actions, .promotion-simulation__case-actions, .promotion-simulation__import-group { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.promotion-simulation__header > div:first-child { min-width: 0; }
.promotion-simulation__header > .promotion-simulation__actions { flex-shrink: 0; }
.promotion-simulation__import-group { flex-wrap: nowrap; min-width: 0; }
.promotion-simulation__actions > .el-button + .el-button,
.promotion-simulation__world-actions > .el-button + .el-button,
.promotion-simulation__case-actions > .el-button + .el-button { margin-left: 0; }
.promotion-simulation__toolbar { padding: 12px; border: 1px solid var(--el-border-color-lighter); border-radius: 8px; background: var(--el-fill-color-blank); }
.promotion-simulation__name { width: 220px; } .promotion-simulation__store { width: 180px; } .promotion-simulation__short { width: 130px; }
.promotion-simulation__note, .promotion-simulation__muted { color: var(--el-text-color-secondary); font-size: 12px; }
.promotion-simulation__worlds { margin-bottom: -10px; }
.promotion-simulation__panel { min-width: 0; padding: 16px; border: 1px solid var(--el-border-color-lighter); border-radius: 10px; background: var(--el-fill-color-blank); }
.promotion-simulation__panel-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; }
.promotion-simulation__panel-header h2 { overflow-wrap: anywhere; }
.promotion-simulation__summary { color: var(--el-text-color-secondary); font-size: 13px; }
.promotion-simulation__revision { min-width: 0; overflow-wrap: anywhere; color: var(--el-text-color-secondary); font-size: 12px; }
.promotion-simulation__cell { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; width: 100%; padding: 8px; border: 0; border-radius: 4px; background: transparent; color: var(--el-text-color-primary); font: inherit; cursor: pointer; text-align: left; }
.promotion-simulation__cell:hover { background: var(--el-fill-color-light); }
.promotion-simulation__cell:focus-visible { outline: 2px solid var(--el-color-primary); outline-offset: -2px; }
.promotion-simulation__cell strong { font-size: 14px; } .promotion-simulation__cell-error { color: var(--el-color-danger); }
.promotion-simulation__totals { display: flex; flex-wrap: wrap; gap: 18px; align-items: center; }
.promotion-simulation__totals span { display: inline-flex; gap: 6px; align-items: center; color: var(--el-text-color-secondary); }
.promotion-simulation__totals strong { color: var(--el-text-color-primary); }
.promotion-simulation__form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 16px; }
.promotion-simulation__form-grid > .el-form-item { min-width: 0; }
.promotion-simulation__full { grid-column: 1 / -1; }
/* Dialogs are teleported without the workbench's scope attribute. */
:global(.promotion-simulation__dialog .el-input-number),
:global(.promotion-simulation__dialog .el-select),
:global(.promotion-simulation__dialog .el-date-editor.el-input) { width: 100%; min-width: 0; }
:global(.promotion-simulation__dialog .el-alert) { margin-bottom: 16px; }
:global(.promotion-simulation__dialog .el-dialog__body) { overflow-wrap: anywhere; }
.promotion-simulation__inline { display: flex; gap: 8px; width: 100%; }
.promotion-simulation__inline > .el-input { flex: 1; min-width: 0; }
.promotion-simulation__add-row { margin-top: 8px; }
@media (max-width: 760px) {
  .promotion-simulation { padding: 12px; }
  .promotion-simulation__header { flex-direction: column; }
  .promotion-simulation__panel { padding: 12px; }
  .promotion-simulation__form-grid { grid-template-columns: 1fr; }
  .promotion-simulation__name, .promotion-simulation__import-group { width: 100%; }
  .promotion-simulation__import-group > .el-input { flex: 1; min-width: 0; }
  .promotion-simulation__case-actions { width: 100%; }
}
</style>
