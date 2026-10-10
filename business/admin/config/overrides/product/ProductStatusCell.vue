<template>
  <el-tag v-if="record.isDeleted" type="info">
    {{ $t('Product off shelf') }}
  </el-tag>
  <el-select
    v-else
    :model-value="record.status"
    size="small"
    :disabled="saving"
    :loading="saving"
    @change="saveStatus"
  >
    <el-option :label="$t('Product on shelf')" value="active" />
    <el-option :label="$t('Product off shelf')" value="inactive" />
  </el-select>
</template>

<script>
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'

export default {
  name: 'ProductStatusCell',
  props: {
    record: { type: Object, required: true },
    refresh: { type: Function, default: null }
  },
  data() {
    return { saving: false }
  },
  methods: {
    async saveStatus(status) {
      if (this.saving || this.record.isDeleted || status === this.record.status) return

      this.saving = true
      try {
        await request.put(apiPath(API_PREFIX, `manage/products/${this.record.id}`), { status })
        this.record.status = status
        this.$message.success(this.$t('Product status updated'))
        this.refresh?.()
      } catch {
        // The shared request client already displays the API error; keep the
        // persisted value in the row unchanged when the update fails.
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.el-select {
  width: 108px;
}
</style>
