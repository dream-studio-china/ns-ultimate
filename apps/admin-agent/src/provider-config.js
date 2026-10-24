export const PROVIDER_PRESETS = Object.freeze({
  deepseek: Object.freeze({
    label: 'DeepSeek',
    baseUrl: 'https://api.deepseek.com/v1',
    defaultModel: 'deepseek-flash',
    modelPlaceholder: 'deepseek-flash'
  }),
  opencodeGo: Object.freeze({
    label: 'OpenCode Go',
    baseUrl: 'https://opencode.ai/zen/go/v1',
    defaultModel: 'glm-5.3-flash',
    modelPlaceholder: 'glm-5.3-flash'
  }),
  ollama: Object.freeze({
    label: 'Ollama',
    baseUrl: 'http://localhost:11434/v1',
    defaultModel: '',
    modelPlaceholder: 'e.g. qwen3.5 or llama3.2'
  }),
  custom: Object.freeze({
    label: 'Custom (OpenAI-compatible)',
    baseUrl: 'https://api.openai.com/v1',
    defaultModel: '',
    modelPlaceholder: 'Enter the model ID'
  })
})

export function normalizeProvider(value, baseUrl = '') {
  if (Object.hasOwn(PROVIDER_PRESETS, value)) return value
  const endpoint = (() => {
    try {
      const url = new URL(String(baseUrl))
      url.pathname = (url.pathname.replace(/\/chat\/completions\/?$/i, '').replace(/\/+$/, '') || '/')
      return `${url.origin}${url.pathname}`.toLowerCase()
    } catch { return String(baseUrl).replace(/\/+$/, '').toLowerCase() }
  })()
  for (const [provider, preset] of Object.entries(PROVIDER_PRESETS)) {
    if (provider === 'custom') continue
    const presetUrl = new URL(preset.baseUrl)
    const canonicalPreset = `${presetUrl.origin}${presetUrl.pathname.replace(/\/+$/, '')}`.toLowerCase()
    if (endpoint === canonicalPreset) return provider
    // Older DeepSeek settings commonly used the API origin without /v1.
    if (provider === 'deepseek' && endpoint === 'https://api.deepseek.com/') return provider
  }
  return 'custom'
}

export function providerUsesApiKey(provider) {
  return provider !== 'ollama'
}
