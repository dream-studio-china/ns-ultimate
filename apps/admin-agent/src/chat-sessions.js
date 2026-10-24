import { trimConversation } from './conversation.js'

/** Browser-local text only. Never stores tool calls, attachments or executable cards. */
export function createChatSessions({ scope = 'browser', storage = () => localStorage, now = Date.now, randomId = () => crypto.randomUUID() } = {}) {
  const key = `ns-ultimate-agent.chats.v1:${encodeURIComponent(scope || 'memory')}`
  let items = []
  let activeId = null
  let persistent = Boolean(scope)
  const copy = value => JSON.parse(JSON.stringify(value))
  try {
    const raw = scope ? storage().getItem(key) : null
    if (raw && raw.length <= 600000) {
      const saved = JSON.parse(raw)
      if (saved?.version === 1 && Array.isArray(saved.items)) {
        items = saved.items.slice(0, 20).filter(item => typeof item?.id === 'string' && item.id.length <= 100 && Number.isFinite(item.updatedAt))
          .map(item => ({ id: item.id, updatedAt: item.updatedAt, messages: trimConversation(item.messages, 40, 24000) }))
          .filter(item => item.messages.length)
        activeId = items.some(item => item.id === saved.activeId) ? saved.activeId : null
      }
    }
  } catch { persistent = false }
  function persist() {
    if (!scope) return
    try { storage().setItem(key, JSON.stringify({ version: 1, activeId, items })); persistent = true }
    catch { persistent = false }
  }
  return {
    get persistent() { return persistent },
    get activeId() { return activeId },
    list() { return copy(items) },
    current() { return copy(items.find(item => item.id === activeId)?.messages || []) },
    save(messages) {
      const clean = trimConversation(messages, 40, 24000)
      if (!clean.length) return
      activeId ||= randomId()
      items = [{ id: activeId, updatedAt: now(), messages: clean }, ...items.filter(item => item.id !== activeId)].slice(0, 20)
      persist()
    },
    select(id) {
      if (!items.some(item => item.id === id)) return false
      activeId = id
      persist()
      return true
    },
    start() { activeId = null; persist() },
    remove(id) { items = items.filter(item => item.id !== id); if (activeId === id) activeId = null; persist() }
  }
}
