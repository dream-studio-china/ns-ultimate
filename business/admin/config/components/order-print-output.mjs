export const PRINT_OUTPUT_PAGE_LIMIT = 200
export const PRINT_OUTPUT_MAX_PAGES = 10

export function eligiblePrintOutputs(property, form, devices, templates) {
  const store = form.storeUuid
  if (!store) return []
  const matchingDevices = devices.filter(device => device.uuid && device.storeUuid === store)
  if (property === 'deviceUuid') return matchingDevices
  const device = matchingDevices.find(item => item.uuid === form.deviceUuid)
  if (!device || !device.kind) return []
  return templates.filter(template => template.uuid && template.kind === device.kind)
}

/** Read one page of a standard admin list response: { data: records[], paginator }. */
export function printOutputPage(response) {
  const records = Array.isArray(response?.data) ? response.data : Array.isArray(response) ? response : null
  if (records === null) throw new Error('Unexpected printer list response')
  return { records, hasNext: response?.paginator?.has_next === true }
}

/**
 * Load every eligible printer record with bounded paging. Exceeding the page
 * bound fails closed instead of silently hiding selectable devices/templates.
 */
export async function loadAllPrintOutputs(list, pageLimit = PRINT_OUTPUT_PAGE_LIMIT, maxPages = PRINT_OUTPUT_MAX_PAGES) {
  const records = []
  for (let page = 1; page <= maxPages; page += 1) {
    const parsed = printOutputPage(await list({ page, limit: pageLimit }))
    records.push(...parsed.records)
    if (!parsed.hasNext) return records
  }
  throw new Error('Too many printer records to list safely')
}
