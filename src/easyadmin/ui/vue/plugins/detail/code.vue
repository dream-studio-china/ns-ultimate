<template>
  <pre class="detail-code"><code>{{ displayValue }}</code></pre>
</template>

<script>
export default {
  name: 'DetailCode',
  props: {
    value: { type: [String, Number, Boolean, Object, Array], default: null }
  },
  computed: {
    displayValue() {
      if (this.value === null || typeof this.value === 'undefined') return '-'
      if (typeof this.value === 'string') return this.value || '-'
      return JSON.stringify(this.value, null, 2)
    }
  }
}
</script>

<style scoped>
.detail-code {
  max-height: 32rem;
  overflow: auto;
  margin: 0;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--surface-muted, #f5f7fa);
  color: var(--text-primary);
  font: 12px/1.6 var(--font-mono, monospace);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>
