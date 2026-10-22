export function trimConversation(messages, maxMessages = 16, maxCharacters = 24000) {
  if (!Array.isArray(messages) || !Number.isInteger(maxMessages) || maxMessages < 1 || !Number.isInteger(maxCharacters) || maxCharacters < 1) return []
  const source = messages.slice(-maxMessages)
  const kept = []
  let remaining = maxCharacters
  for (let index = source.length - 1; index >= 0 && remaining > 0; index -= 1) {
    const message = source[index]
    if (!message || !['user', 'assistant'].includes(message.role) || typeof message.content !== 'string') continue
    const content = message.content.length <= remaining ? message.content : message.content.slice(-remaining)
    kept.push({ role: message.role, content })
    remaining -= content.length
    if (content.length < message.content.length) break
  }
  return kept.reverse()
}
