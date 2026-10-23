// Browser router boundary. No history, window, DOM, or navigation is used.
const router = {
  currentRoute: { value: { path: '/', fullPath: '/' } },
  options: { routes: [] },
  getRoutes: () => [],
  resolve: path => ({ path, matched: [] }),
  replace: () => Promise.resolve(),
  push: () => Promise.resolve()
}

export default router
