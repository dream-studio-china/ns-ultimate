// ns-ultimate host allowlist for read-only nested configuration inspection.
// The generic agent transport never infers inspectable entities or fields
// from names: only these entities, identifiers, and scalar nested paths are
// readable, and only through their documented GET detail endpoints.
// Sensitive material (credentials, tokens, contact data, full settings blobs,
// snapshots, metadata) is deliberately absent; paths are fixed here and are
// never supplied by the model.

const PRINTING_CASES = ['order', 'perItem', 'filteredList', 'fullList', 'settlement']
const PRINTING_CASE_FIELDS = ['enabled', 'deviceUuid', 'templateUuid', 'copies']

const storePaths = [
  'code',
  'name',
  'status',
  'settings.order.requireAcceptance',
  ...PRINTING_CASES.flatMap(caseKey =>
    PRINTING_CASE_FIELDS.map(field => `settings.printing.${caseKey}.${field}`))
]

export const recordInspectionPolicy = {
  Store: { paths: storePaths },
  Product: { paths: ['name', 'status', 'settings.kitchenPrinterEnable'] }
}

export default recordInspectionPolicy
