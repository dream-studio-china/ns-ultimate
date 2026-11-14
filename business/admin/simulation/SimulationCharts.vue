<template>
  <div class="simulation-charts">
    <section class="simulation-chart">
      <h3>{{ $t('World amount comparison') }}</h3>
      <p>{{ $t('Successful runs only; currencies are shown separately.') }}</p>
      <div class="simulation-chart__legend"><span class="before">{{ $t('Before') }}</span><span class="after">{{ $t('After') }}</span></div>
      <div v-for="row in rows" :key="row.key" class="simulation-chart__row">
        <div class="simulation-chart__heading"><strong>{{ row.name }} · {{ row.currency }}</strong><small>{{ row.valid }} / {{ row.count }}</small></div>
        <template v-if="row.valid">
          <div class="simulation-chart__amount"><span class="simulation-chart__track"><i class="before" :style="{ width: width(row.before, row.currency) }" /></span><span>{{ formatMinor(row.before, row.currency) }}</span></div>
          <div class="simulation-chart__amount"><span class="simulation-chart__track"><i class="after" :style="{ width: width(row.after, row.currency) }" /></span><span>{{ formatMinor(row.after, row.currency) }}</span></div>
        </template>
        <small v-else>{{ $t('No successful results') }}</small>
      </div>
    </section>
    <section class="simulation-chart">
      <h3>{{ $t('Verification coverage') }}</h3>
      <p>{{ $t('Unverified runs need expected results; they are not passes.') }}</p>
      <div class="simulation-chart__legend"><span v-for="state in states" :key="state.key" :class="state.key">{{ $t(state.label) }}</span></div>
      <div v-for="row in rows" :key="row.key" class="simulation-chart__row">
        <div class="simulation-chart__heading"><strong>{{ row.name }} · {{ row.currency }}</strong><small>{{ row.count }}</small></div>
        <div class="simulation-chart__stack" :aria-label="states.map(state => `${$t(state.label)}: ${row[state.key]}`).join(',')" role="img">
          <i v-for="state in states" :key="state.key" :class="state.key" :style="{ width: `${row.count ? row[state.key] / row.count * 100 : 0}%` }" />
        </div>
        <div class="simulation-chart__counts"><span v-for="state in states" :key="state.key">{{ $t(state.label) }} {{ row[state.key] }}</span></div>
      </div>
    </section>
  </div>
</template>

<script>
import { formatMinor, resultOverview } from './scenario.mjs'
export default {
  name: 'SimulationCharts',
  props: { result: { type: Object, required: true } },
  data: () => ({ states: [{ key: 'passed', label: 'Verified' }, { key: 'failed', label: 'Failed' }, { key: 'errors', label: 'Errors' }, { key: 'unverified', label: 'Not verified' }] }),
  computed: { rows() { return resultOverview(this.result) } },
  methods: {
    formatMinor,
    width(amount, currency) {
      const max = Math.max(1, ...this.rows.filter(row => row.currency === currency).flatMap(row => [row.before, row.after]))
      return `${Math.max(0, amount) / max * 100}%`
    }
  }
}
</script>

<style scoped>
.simulation-charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.simulation-chart { min-width: 0; border: 1px solid var(--el-border-color-lighter); padding: 18px; border-radius: 12px; background: var(--el-fill-color-blank); }
.simulation-chart h3 { font-size: 15px; margin: 0 0 8px; }
.simulation-chart p, .simulation-chart small { color: var(--el-text-color-secondary); font-size: 12px; margin: 0; }
.simulation-chart__legend, .simulation-chart__counts { display: flex; gap: 12px; flex-wrap: wrap; font-size: 12px; color: var(--el-text-color-secondary); margin-top: 12px; }
.simulation-chart__legend > span::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 5px; background: var(--bar-color); }
.before { --bar-color: var(--el-color-info-light-5); }
.after { --bar-color: var(--el-color-primary); }
.passed { --bar-color: var(--el-color-success); }
.failed { --bar-color: var(--el-color-warning); }
.errors { --bar-color: var(--el-color-danger); }
.unverified { --bar-color: var(--el-color-info); }
.simulation-chart__row { margin-top: 18px; }
.simulation-chart__heading { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 8px; font-size: 13px; overflow-wrap: anywhere; }
.simulation-chart__amount { display: flex; gap: 12px; align-items: center; font-size: 12px; margin-top: 6px; font-variant-numeric: tabular-nums; }
.simulation-chart__amount > span:last-child { flex: 0 0 112px; text-align: right; }
.simulation-chart__track { flex: 1; background: var(--el-fill-color-light); border-radius: 4px; overflow: hidden; }
.simulation-chart__track i, .simulation-chart__stack i { display: block; background: var(--bar-color); height: 12px; }
.simulation-chart__stack { display: flex; overflow: hidden; border-radius: 4px; background: var(--el-fill-color-light); min-height: 12px; }
@media (max-width: 900px) { .simulation-charts { grid-template-columns: 1fr; } }
</style>
