const MAX_TOOL_CALLS_PER_TASK = 100
const MAX_IDENTICAL_CONSECUTIVE_CALLS = 3

function stableStringify(value) {
  if (Array.isArray(value)) return `[${value.map(stableStringify).join(',')}]`
  if (value && typeof value === 'object') {
    return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`
  }
  return JSON.stringify(value)
}

export function createToolLoopGuard() {
  let calls = 0
  let previousKey = ''
  let repeatedCalls = 0

  return {
    check(call) {
      if (calls >= MAX_TOOL_CALLS_PER_TASK) return { allowed: false, reason: 'limit' }
      calls += 1

      let argumentsValue = call?.function?.arguments || ''
      try { argumentsValue = stableStringify(JSON.parse(argumentsValue)) } catch { /* Invalid arguments are rejected by the tool executor. */ }
      const key = `${call?.function?.name || ''}:${argumentsValue}`
      if (key === previousKey) repeatedCalls += 1
      else {
        previousKey = key
        repeatedCalls = 1
      }
      if (repeatedCalls > MAX_IDENTICAL_CONSECUTIVE_CALLS) return { allowed: false, reason: 'repeated' }
      return { allowed: true }
    }
  }
}
