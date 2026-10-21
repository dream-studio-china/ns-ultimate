export function normalizeChatEndpoint(baseUrl) {
  const url = new URL(baseUrl)
  if (url.protocol !== 'https:' && url.hostname !== 'localhost' && url.hostname !== '127.0.0.1' && url.hostname !== '[::1]') {
    throw new TypeError('Provider endpoints must use HTTPS, except for localhost.')
  }
  if (url.username || url.password || url.search || url.hash) {
    throw new TypeError('Provider URLs must not contain credentials, query parameters, or fragments.')
  }
  const basePath = url.pathname.replace(/\/+$/, '')
  url.pathname = basePath.endsWith('/chat/completions') ? basePath : `${basePath}/chat/completions`
  return url.href
}

export async function askProvider({ baseUrl, model, apiKey, messages, fetchImpl = fetch }) {
  if (!apiKey) throw new Error('Enter an API key for this session.')
  if (!model.trim()) throw new Error('Enter a model name.')
  const endpoint = normalizeChatEndpoint(baseUrl)
  const response = await fetchImpl(endpoint, {
    method: 'POST',
    credentials: 'omit',
    redirect: 'error',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${apiKey}` },
    body: JSON.stringify({ model, messages, stream: false })
  })
  if (!response.ok) throw new Error(`Provider request failed (${response.status}).`)
  const result = await response.json()
  const content = result?.choices?.[0]?.message?.content
  if (typeof content !== 'string' || !content.trim()) throw new Error('Provider returned no text response.')
  return content
}
