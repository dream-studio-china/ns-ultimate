// Vuex modules are not evaluated during manifest export. Configuration code
// only reads/store methods inside lazy callbacks.
const store = {
  state: {},
  getters: {},
  dispatch: () => Promise.resolve(),
  commit: () => {}
}

export default store
