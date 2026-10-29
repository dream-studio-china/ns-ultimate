<template>
  <el-select v-model="form[property]" multiple :placeholder="$t('Select print events')">
    <el-option value="confirmed" :label="$t('Order confirmed')" />
    <el-option value="paid" :label="$t('Payment succeeded')" />
    <el-option value="accepted" :label="$t('Store accepted')" />
  </el-select>
</template>

<script>
export default {
  props: { form: { type: Object, required: true }, property: { type: String, required: true } }
}
</script>
