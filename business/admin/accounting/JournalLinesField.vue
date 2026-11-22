<template>
  <div class="journal-lines">
    <el-table :data="editing ? rows : stored" :row-key="editing ? 'key' : 'position'" stripe border>
      <el-table-column type="index" width="50" />
      <el-table-column :label="$t('Account code')" min-width="210">
        <template #default="{ row }">
          <el-select v-if="editing" v-model="row.account_code" filterable :aria-label="$t('Account code')">
            <el-option
              v-for="account in accounts" :key="account.code" :value="account.code"
              :label="`${account.code} - ${account.name}`" :disabled="!postable(account)"
            />
          </el-select>
          <span v-else>{{ accountLabel(row.account_code) }}</span>
        </template>
      </el-table-column>
      <el-table-column :label="$t('Debit or credit')" min-width="125">
        <template #default="{ row }">
          <el-select v-if="editing" v-model="row.side" :aria-label="$t('Debit or credit')">
            <el-option v-for="side in sides" :key="side" :value="side" :label="$t(side)" />
          </el-select>
          <span v-else>{{ $t(row.side) }}</span>
        </template>
      </el-table-column>
      <el-table-column :label="$t('Amount')" min-width="145" align="right">
        <template #default="{ row }">
          <el-input v-if="editing" v-model.trim="row.amount" inputmode="decimal" autocomplete="off" :aria-label="$t('Amount')" />
          <span v-else class="journal-lines__money">{{ finance.money(row.amount) }}</span>
        </template>
      </el-table-column>
      <el-table-column :label="$t('Description')" min-width="180">
        <template #default="{ row }">
          <el-input v-if="editing" v-model="row.description" maxlength="240" :aria-label="$t('Description')" />
          <span v-else>{{ row.description || '—' }}</span>
        </template>
      </el-table-column>
      <el-table-column v-if="editing" :label="$t('Actions')" width="105">
        <template #default="{ $index }">
          <el-button link type="danger" @click="rows.splice($index, 1)">{{ $t('Remove line') }}</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-space wrap class="journal-lines__toolbar">
      <el-button v-if="editing" :disabled="rows.length >= 100" @click="rows.push(editorLine('debit'))">{{ $t('Add line') }}</el-button>
      <template v-if="totals">
        <el-tag effect="plain">{{ $t('debit') }}: {{ totals.debit }}</el-tag>
        <el-tag effect="plain">{{ $t('credit') }}: {{ totals.credit }}</el-tag>
        <el-tag v-if="totals.debit !== totals.credit" type="warning">{{ $t('Unbalanced draft') }}</el-tag>
      </template>
    </el-space>
  </div>
</template>

<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue'
import { t } from '@/i18n'
import { finance } from './finance-context'
import { draftLines, draftTotals, editorLine, editorLines, lineError } from './ledger-ui.mjs'

/**
 * Journal lines field for FormAdmin (`form` given) and DetailAdmin (`data`).
 *
 * EasyAdmin's `array` plugin cannot express this: its sub-forms load an
 * entity structure named "Option" that does not exist, and it has no account
 * choices from the chart, exact decimal-to-minor-unit conversion, or totals.
 * The form value is the exact command payload: minor-unit amount strings.
 */
defineOptions({ inheritAttrs: false })
const props = defineProps({
  form: { type: Object, default: null },
  property: { type: String, default: 'lines' },
  data: { type: Array, default: null }
})
const registerFieldValidator = inject('registerFieldValidator', null)
const sides = ['debit', 'credit']
const editing = computed(() => props.form !== null)
const accounts = computed(() => finance.accounts)
const stored = computed(() => props.data ?? [])
const rows = ref([])
// The form object the rows were seeded from, and the payload last written to it.
let source = null
let emitted

const postable = account => Boolean(account.active) && Boolean(account.postable)
const accountLabel = code => {
  const account = finance.accounts.find(entry => entry.code === code)
  return account ? `${code} - ${account.name}` : code
}
const totals = computed(() => {
  if (editing.value) return draftTotals(rows.value, finance.scale)
  return draftTotals(stored.value.map(line => ({ ...line, amount: finance.money(line.amount) })), finance.scale)
})

function seed(value) {
  source = toRaw(props.form)
  rows.value = Array.isArray(value) && value.length
    ? editorLines(value, finance.scale)
    : [editorLine('debit'), editorLine('credit')]
}

if (editing.value) {
  // Reseed when FormAdmin loads or replaces its form; ignore our own writes.
  // Before the workspace (book scale) is known, the mount hook seeds instead.
  watch(() => [toRaw(props.form), props.form[props.property]], ([form, value]) => {
    if (finance.workspace && (form !== source || toRaw(value) !== emitted)) seed(value)
  })
  watch(rows, value => {
    // Rows seeded from a replaced form must not overwrite the loaded record.
    if (toRaw(props.form) !== source) return
    let lines = null
    try {
      if (!lineError(value, finance.scale)) lines = draftLines(value, finance.scale)
    } catch {
      lines = null
    }
    emitted = lines
    props.form[props.property] = lines
  }, { deep: true })
}

let release
onMounted(async() => {
  if (!editing.value) return
  await finance.ensure().catch(() => {})
  seed(props.form[props.property])
  release = registerFieldValidator?.(props.property, (_rule, _value, callback) => {
    const error = lineError(rows.value, finance.scale)
    callback(error ? new Error(t(error)) : undefined)
  }, 'change')
})
onBeforeUnmount(() => release?.())
</script>

<style scoped>
.journal-lines { width: 100%; }
.journal-lines__toolbar { margin-top: 12px; }
.journal-lines__money { font-variant-numeric: tabular-nums; }
</style>
