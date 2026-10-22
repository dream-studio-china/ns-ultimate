import { MAX_FILE_BYTES, MAX_TOTAL_BYTES, validateImageDataUrl } from './attachments.js'

export function normalizeChatEndpoint(baseUrl) {
  const url = new URL(baseUrl)
  if (url.protocol !== 'https:' && !isAllowedHttpHost(url.hostname)) {
    throw new TypeError('Provider endpoints must use HTTPS, except for localhost or private LAN addresses.')
  }
  if (url.username || url.password || url.search || url.hash) {
    throw new TypeError('Provider URLs must not contain credentials, query parameters, or fragments.')
  }
  const basePath = url.pathname.replace(/\/+$/, '') || '/v1'
  url.pathname = basePath.endsWith('/chat/completions') ? basePath : `${basePath}/chat/completions`
  return url.href
}

function isAllowedHttpHost(hostname) {
  const host = hostname.toLowerCase().replace(/^\[|\]$/g, '')
  if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local') || host.endsWith('.lan') || host === '::1') return true
  if (host.includes(':')) {
    const firstSegment = Number.parseInt(host.split(':')[0] || '0', 16)
    return Number.isInteger(firstSegment)
      && ((firstSegment & 0xfe00) === 0xfc00 || (firstSegment & 0xffc0) === 0xfe80)
  }

  const octets = host.split('.').map(part => /^\d{1,3}$/.test(part) ? Number(part) : NaN)
  if (octets.length !== 4 || octets.some(part => !Number.isInteger(part) || part < 0 || part > 255)) return false
  const [first, second] = octets
  return first === 127 || first === 10
    || first === 192 && second === 168
    || first === 172 && second >= 16 && second <= 31
    // Carrier-grade NAT/shared address space is commonly used by private overlays.
    || first === 100 && second >= 64 && second <= 127
}

export const MAX_PROVIDER_REQUEST_BYTES = 16 * 1024 * 1024

function encodeProviderRequest(payload) {
  let body
  try {
    body = JSON.stringify(payload)
  } catch {
    throw new TypeError('Provider request could not be serialized.')
  }
  const bytes = new TextEncoder().encode(body).byteLength
  if (bytes > MAX_PROVIDER_REQUEST_BYTES) {
    throw new RangeError(`Provider request exceeded the ${MAX_PROVIDER_REQUEST_BYTES} byte limit.`)
  }
  return body
}

function validateUserContent(content) {
  if (typeof content === 'string') return
  if (!Array.isArray(content) || content.length === 0) {
    throw new TypeError('Provider user message content must be text or multimodal parts.')
  }
  let imageBytes = 0
  for (const part of content) {
    if (!part || typeof part !== 'object' || Array.isArray(part)) {
      throw new TypeError('Provider user message contains an invalid content part.')
    }
    if (part.type === 'text') {
      if (typeof part.text !== 'string' || Object.keys(part).some(key => key !== 'type' && key !== 'text')) {
        throw new TypeError('Provider text content part is invalid.')
      }
      continue
    }
    if (part.type === 'image_url') {
      if (!part.image_url || typeof part.image_url !== 'object' || Array.isArray(part.image_url)
        || typeof part.image_url.url !== 'string'
        || Object.keys(part).some(key => key !== 'type' && key !== 'image_url')
        || Object.keys(part.image_url).some(key => key !== 'url')) {
        throw new TypeError('Provider image content part is invalid.')
      }
      const image = validateImageDataUrl(part.image_url.url)
      if (image.byteLength > MAX_FILE_BYTES) throw new RangeError('Provider image exceeds the size limit.')
      imageBytes += image.byteLength
      if (imageBytes > MAX_TOTAL_BYTES) throw new RangeError('Provider images exceed the total size limit.')
      continue
    }
    throw new TypeError('Provider user message contains an unsupported content part.')
  }
}

export function assertProviderMessages(messages) {
  if (!Array.isArray(messages)) throw new TypeError('Provider messages must be an array.')
  for (const message of messages) {
    if (!message || typeof message !== 'object' || Array.isArray(message)) {
      throw new TypeError('Provider messages contain an invalid entry.')
    }
    if (!['system', 'user', 'assistant', 'tool'].includes(message.role)) {
      throw new TypeError('Provider message role is invalid.')
    }
    if (message.role === 'user') {
      validateUserContent(message.content)
      continue
    }
    if (message.role === 'assistant') {
      if (message.content !== null && typeof message.content !== 'string') {
        throw new TypeError('Provider assistant content must be text or null.')
      }
      if (message.tool_calls !== undefined && !Array.isArray(message.tool_calls)) {
        throw new TypeError('Provider assistant tool calls are invalid.')
      }
      continue
    }
    if (typeof message.content !== 'string') {
      throw new TypeError('Provider system and tool content must be text.')
    }
  }
}

export async function askProvider({ baseUrl, model, apiKey, messages, tools, signal, onToken, fetchImpl = fetch }) {
  if (!model.trim()) throw new Error('Enter a model name.')
  assertProviderMessages(messages)
  const endpoint = normalizeChatEndpoint(baseUrl)
  const body = encodeProviderRequest({
    model,
    messages,
    stream: true,
    ...(Array.isArray(tools) && tools.length ? { tools, tool_choice: 'auto' } : {})
  })
  const response = await fetchImpl(endpoint, {
    method: 'POST',
    credentials: 'omit',
    redirect: 'error',
    signal,
    headers: {
      'Content-Type': 'application/json',
      ...(typeof apiKey === 'string' && apiKey.length ? { Authorization: `Bearer ${apiKey}` } : {})
    },
    body
  })
  if (!response.ok) throw new Error(`Provider request failed (${response.status}).`)
  let message
  if (response.body?.getReader) {
    const reader = response.body.getReader()
    const decoder = new TextDecoder()
    let buffer = ''
    let content = ''
    const calls = new Map()
    const consumeEvent = data => {
      if (!data || data === '[DONE]') return
      let chunk
      try { chunk = JSON.parse(data) } catch { throw new TypeError('Provider returned an invalid streaming response.') }
      const delta = chunk?.choices?.[0]?.delta
      if (typeof delta?.content === 'string') {
        content += delta.content
        if (content.length > 16000) throw new RangeError('Provider response exceeded the size limit.')
        onToken?.(delta.content)
      }
      for (const part of delta?.tool_calls || []) {
        const index = Number.isInteger(part.index) ? part.index : calls.size
        const call = calls.get(index) || { id: '', type: 'function', function: { name: '', arguments: '' } }
        if (part.id) call.id += part.id
        if (part.type) call.type = part.type
        if (part.function?.name) call.function.name += part.function.name
        if (part.function?.arguments) call.function.arguments += part.function.arguments
        calls.set(index, call)
      }
    }
    try {
      while (true) {
        const { value, done } = await reader.read()
        buffer += decoder.decode(value || new Uint8Array(), { stream: !done }).replace(/\r\n/g, '\n')
        let boundary
        while ((boundary = buffer.indexOf('\n\n')) >= 0) {
          const event = buffer.slice(0, boundary)
          buffer = buffer.slice(boundary + 2)
          const data = event.split(/\r?\n/).filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n')
          consumeEvent(data)
        }
        if (done) break
      }
      if (buffer.trim()) {
        const data = buffer.split(/\r?\n/).filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n')
        consumeEvent(data)
      }
    } catch (error) {
      await reader.cancel().catch(() => {})
      throw error
    }
    message = { content, tool_calls: [...calls.values()] }
  } else {
    const result = await response.json()
    message = result?.choices?.[0]?.message
  }
  const content = message?.content
  const toolCalls = Array.isArray(message?.tool_calls) ? message.tool_calls.slice(0, 8) : []
  if ((typeof content !== 'string' || !content.trim()) && toolCalls.length === 0) throw new Error('Provider returned no text response or tools.')
  if (typeof content === 'string' && content.length > 16000) throw new RangeError('Provider response exceeded the size limit.')
  const normalizedCalls = toolCalls.map(call => ({
    id: typeof call?.id === 'string' ? call.id.slice(0, 160) : '',
    type: call?.type,
    function: {
      name: typeof call?.function?.name === 'string' ? call.function.name.slice(0, 100) : '',
      arguments: typeof call?.function?.arguments === 'string' ? call.function.arguments : ''
    }
  }))
  const callIds = new Set()
  for (const call of normalizedCalls) {
    if (!call.id || call.type !== 'function' || !call.function.name || !call.function.arguments || call.function.arguments.length > 10000 || callIds.has(call.id)) {
      throw new TypeError('Provider returned an invalid tool call.')
    }
    callIds.add(call.id)
  }
  return {
    content: typeof content === 'string' ? content : '',
    toolCalls: normalizedCalls,
    message: { role: 'assistant', content: typeof content === 'string' ? content : null, ...(normalizedCalls.length ? { tool_calls: normalizedCalls } : {}) }
  }
}
