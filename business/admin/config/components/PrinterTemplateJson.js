import JsonField from '@/easyadmin/ui/vue/plugins/form/json.vue'
import JSONEditor from 'jsoneditor'

// Reuse the original JSON field's rendering, modes and teardown. Only its value
// adapter differs: the designer needs raw invalid drafts, not just parsed JSON.
export default {
  name: 'PrinterTemplateJson',
  extends: JsonField,
  props: { modelValue: { type: String, required: true } },
  emits: ['update:modelValue'],
  data() { return { disposed: false, syncing: false } },
  watch: {
    modelValue() { this.syncText() }
  },
  beforeUnmount() { this.disposed = true },
  methods: {
    createEditor() {
      this.$nextTick(() => {
        if (this.disposed) return
        this.editor = new JSONEditor(this.$refs.container, {
          mode: this.editorMode,
          modes: this.editorModes,
          onChangeText: text => {
            if (!this.syncing && !this.disposed) this.$emit('update:modelValue', text)
          }
        })
        this.syncText()
      })
    },
    syncText() {
      if (!this.editor || this.editor.getText() === this.modelValue) return
      this.syncing = true
      try {
        try { JSON.parse(this.modelValue) } catch {
          // Tree/form cannot display an invalid undo snapshot. Keep that draft
          // intact by returning to code mode instead of discarding it.
          if (!['code', 'text'].includes(this.editor.getMode())) this.editor.setMode('code')
        }
        this.editor.updateText(this.modelValue)
      } finally { this.syncing = false }
    }
  }
}
