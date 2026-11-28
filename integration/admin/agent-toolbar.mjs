/** Mount into the core navbar without putting the assistant panel inside it. */
export function mountAdminAgentLauncher(launcher) {
  const { ownerDocument } = launcher
  const app = ownerDocument.getElementById('app')
  if (!app) return

  const attach = () => {
    if (app.contains(launcher)) return
    app.querySelector('.navbar > .right-menu')?.prepend(launcher)
  }
  // The session loader can run before Vue renders the navbar. The navbar can
  // also be replaced while the same authenticated session is still active.
  const observer = new ownerDocument.defaultView.MutationObserver(attach)
  observer.observe(app, { childList: true, subtree: true })
  attach()
  return () => {
    observer.disconnect()
    launcher.remove()
  }
}
