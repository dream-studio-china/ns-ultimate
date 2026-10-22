function csvValue(value) {
  let text = Array.isArray(value) ? value.join(', ') : String(value ?? '')
  if (/^[\s\u0000-\u001f]*[=+\-@]/.test(text)) text = `'${text}`
  return `"${text.replaceAll('"', '""')}"`
}

export function toSafeCsv(records, properties, labels = new Map()) {
  if (!Array.isArray(records) || !Array.isArray(properties)) throw new TypeError('CSV export requires records and configured properties.')
  const columns = properties.filter(property => typeof property === 'string')
  const rows = [columns.map(property => csvValue(labels.get(property) || property))]
  for (const record of records) {
    rows.push(columns.map(property => csvValue(record?.[property])))
  }
  return `\uFEFF${rows.map(row => row.join(',')).join('\r\n')}`
}
