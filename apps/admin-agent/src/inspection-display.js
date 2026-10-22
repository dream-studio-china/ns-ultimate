const REASON_KEYS = Object.freeze({
  missing: 'Missing from response',
  'null-ancestor': 'Parent setting is null',
  invalid: 'Present but not a scalar value',
  oversized: 'Value exceeds inspection limit'
})

// Convert an untrusted tool result to bounded display strings. The inspection
// host is still authoritative for which paths are allowed; this helper only
// prevents malformed tool output from becoming an unbounded UI surface.
export function formatInspectionOmissions(omissions, translate) {
  if (!Array.isArray(omissions) || typeof translate !== 'function') return []
  return omissions.slice(0, 40).flatMap(item => {
    if (!item || typeof item.path !== 'string' || item.path.length > 200 || typeof item.reason !== 'string' || !Object.hasOwn(REASON_KEYS, item.reason)) return []
    return [`${item.path}: ${translate(REASON_KEYS[item.reason])}`]
  })
}
