export function createWritePreview(input, policy, snapshots) {
  if (!input || typeof input !== 'object' || Array.isArray(input)
    || Object.keys(input).some(key => !['entity', 'identifier', 'changes'].includes(key))) {
    throw new TypeError('A preview requires only entity, identifier and changes.')
  }
  const fields = Object.hasOwn(policy, input.entity) ? policy[input.entity] : null
  if (!fields) throw new TypeError('This entity is not enabled for write previews.')
  if (typeof input.identifier !== 'string' || !/^(?:[1-9]\d{0,14}|[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/i.test(input.identifier)) {
    throw new TypeError('An exact numeric ID or UUID is required.')
  }
  const snapshot = snapshots.get(input.entity)
  const matches = snapshot?.records.filter(record => [record.id, record.uuid].some(value => value !== undefined && String(value) === input.identifier)) || []
  if (matches.length !== 1) throw new TypeError('Query the target record first and provide its exact ID or UUID from the latest result.')
  const changes = input.changes
  if (!changes || typeof changes !== 'object' || Array.isArray(changes) || !Object.keys(changes).length || Object.keys(changes).length > 5) {
    throw new TypeError('Provide between one and five explicit field changes.')
  }
  const record = matches[0]
  const differences = Object.entries(changes).map(([property, after]) => {
    if (!Object.hasOwn(fields, property)) throw new TypeError('This field is not enabled for write previews.')
    const rule = fields[property]
    if (rule.type !== 'string' || typeof after !== 'string' || after.length > rule.maxLength
      || /[\u0000-\u001f\u007f]/.test(after) || (rule.required && !after.trim())) {
      throw new TypeError(`The proposed value for ${property} is invalid.`)
    }
    if (!Object.hasOwn(record, property) || typeof record[property] !== 'string' || record[property].length >= 300) {
      throw new TypeError('The current field value is missing or may be truncated; a reliable preview is unavailable.')
    }
    return { property, label: rule.label, before: record[property], after, changed: record[property] !== after }
  })
  return {
    previewOnly: true, saved: false, action: 'update', entity: input.entity,
    identifier: input.identifier, affectedRecords: 1, observedAt: snapshot.observedAt,
    validation: 'Local field validation only; backend authorization and business rules are not verified. Values are a query snapshot, not a current-state guarantee.',
    differences
  }
}
