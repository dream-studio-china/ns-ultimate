// SearchFilter expects a full-style filter, not a form relation descriptor.
export function uuidFilter(property, label, loadRecords) {
  return () => Promise.resolve(loadRecords()).then(records => ({
    label,
    type: 'select',
    default: null,
    expression: `entity.get${property[0].toUpperCase()}${property.slice(1)}() == ':value'`,
    data: records.filter(record => typeof record.uuid === 'string' && record.uuid).map(record => ({
      value: record.uuid,
      label: record.__toString || record.name || record.code || record.uuid
    }))
  }))
}
