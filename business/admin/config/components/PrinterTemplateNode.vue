<template>
  <li class="template-node">
    <div
      class="template-node-row" :class="{ selected: selected === pathKey, unsupported: !knownTypes.includes(node.type) }"
      draggable="true" @dragstart.stop="startDrag" @dragover.prevent.stop @drop.prevent.stop="drop"
    >
      <button type="button" :aria-pressed="selected === pathKey" @click="$emit('select', path)">
        <span aria-hidden="true">⠿</span> {{ node.type }} <span class="node-summary">{{ summary }}</span>
      </button>
    </div>
    <ol v-if="Array.isArray(node.children)">
      <PrinterTemplateNode
        v-for="(child, index) in node.children" :key="index" :node="child" :path="[...path, index]" :selected="selected"
        @select="$emit('select', $event)" @move="$emit('move', $event)"
      />
    </ol>
  </li>
</template>

<script>
import { knownTypes } from './printer-template-designer.mjs'

export default {
  name: 'PrinterTemplateNode',
  props: { node: { type: Object, required: true }, path: { type: Array, required: true }, selected: { type: String, default: '' } },
  emits: ['select', 'move'],
  computed: {
    knownTypes: () => knownTypes,
    pathKey() { return this.path.join('.') },
    summary() { return String(this.node.template ?? this.node.value ?? this.node.path ?? this.node.countPath ?? '').slice(0, 80) }
  },
  methods: {
    startDrag(event) { event.dataTransfer.setData('application/x-printer-node', JSON.stringify(this.path)); event.dataTransfer.effectAllowed = 'move' },
    drop(event) {
      try {
        const source = JSON.parse(event.dataTransfer.getData('application/x-printer-node'))
        if (Array.isArray(source) && source.every(Number.isInteger)) this.$emit('move', { source, target: this.path })
      } catch { /* Ignore drags from outside this designer. */ }
    }
  }
}
</script>

<style scoped>
.template-node { list-style: none; margin: 4px 0; }
ol { padding-left: 16px; margin: 0; border-left: 1px solid var(--el-border-color); }
.template-node-row { border: 1px solid var(--el-border-color); border-radius: 4px; background: var(--el-bg-color); }
.selected { border-color: var(--el-color-primary); background: var(--el-color-primary-light-9); }
.unsupported { border-style: dashed; }
button { display: block; padding: 8px; width: 100%; text-align: left; cursor: grab; color: inherit; background: none; border: 0; font: inherit; }
.node-summary { opacity: .65; overflow-wrap: anywhere; }
</style>
