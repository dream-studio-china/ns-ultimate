import { createOperationRecovery } from './operation-recovery.js'

/** Separate from chat: receipts and restored field values are never added to model messages. */
export function mountOperationHistory(document, host, { t, canInteract, onBusy, onRestore }) {
  const node = document.createElement('section')
  node.className = 'operation-history hidden'
  node.setAttribute('aria-label', t('Operations for this account'))
  const make = (tag, text, className = '') => {
    const element = document.createElement(tag)
    element.textContent = text
    element.className = className
    return element
  }
  const recovery = createOperationRecovery(host, {
    canInteract, onBusy, onRestore,
    onChange: render
  })
  function render(state) {
    node.replaceChildren(make('strong', t('Operations for this account')),
      make('p', t('Only your operations are shown. Restore a pending preview to review and confirm it again; writes are never retried automatically.')))
    const button = (label, action) => {
      const control = make('button', t(label), 'button secondary')
      control.type = 'button'
      control.disabled = state.busy
      control.addEventListener('click', action)
      return control
    }
    const actions = make('div', '', 'row')
    actions.append(button('Refresh operations', () => recovery.load(1)))
    if (state.page > 1) actions.append(button('Previous operations', () => recovery.load(state.page - 1)))
    if (state.hasMore) actions.append(button('Older operations', () => recovery.load(state.page + 1)))
    node.append(actions, make('p', t('page {page}', { page: state.page })))
    if (state.error) node.append(make('p', t(state.error), 'message error'))
    if (!state.items.length && !state.busy && !state.error) node.append(make('p', t('No accessible operations on this page.')))
    for (const receipt of state.items) {
      const card = make('div', '', 'message')
      card.append(make('strong', `${receipt.entity} · ${t(receipt.action)} · ${t(receipt.status)}`),
        make('p', `${t('Operation ID')}: ${receipt.operationId}`),
        make('p', `${t('Target')}: ${receipt.identifier ?? t('New record')}`),
        make('p', `${t('Created')}: ${receipt.createdAt} · ${t('Expires')}: ${receipt.expiresAt}`))
      const controls = make('div', '', 'row')
      controls.append(button('Check outcome', () => recovery.check(receipt.operationId)))
      if (receipt.status === 'pending') controls.append(button('Restore preview', () => recovery.restore(receipt.operationId)))
      card.append(controls)
      node.append(card)
    }
  }
  render(recovery.state)
  return { node, load: () => recovery.load(1), reset: () => recovery.reset(), dispose: () => { recovery.dispose(); node.replaceChildren() } }
}
