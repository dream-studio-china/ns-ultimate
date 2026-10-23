// Element Plus imports in configuration are component references or lazy UI
// callbacks. No Element Plus runtime, DOM, or plugin installation is needed.
function component(name) {
  return { name }
}

export const ElButton = component('ElButton')
export const ElDialog = component('ElDialog')
export const ElInput = component('ElInput')
export const ElOption = component('ElOption')
export const ElSelect = component('ElSelect')
export const ElTag = component('ElTag')
export const ElTransfer = component('ElTransfer')
export const ElAlert = component('ElAlert')

export const ElMessage = {
  success() {},
  warning() {},
  error() {},
  info() {}
}
