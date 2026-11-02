export const DEFAULT_CUSTOMER_ORDERING_BASE_URL = 'https://ns-ultimate.wscoffee.com.cn'

const configuredOrderingBaseUrl = import.meta.env.VITE_CUSTOMER_ORDERING_BASE_URL
  || DEFAULT_CUSTOMER_ORDERING_BASE_URL

export function customerOrderingUrl(counterUuid: string, storeUuid: string, baseUrl = configuredOrderingBaseUrl): string {
  const id = counterUuid.trim()
  if (!id) throw new Error('counterUuid is required')
  const storeId = storeUuid.trim()
  if (!storeId) throw new Error('storeUuid is required')
  const base = new URL(`${baseUrl.replace(/\/+$/, '')}/`)
  if (base.protocol !== 'https:' && base.protocol !== 'http:') throw new Error('invalid ordering base URL')
  const url = new URL('h5/order/index.html', base)
  url.searchParams.set('counterId', id)
  url.searchParams.set('storeUuid', storeId)
  return url.toString()
}

function safeFileStem(value: string): string {
  const cleaned = value
    .trim()
    .replace(/[\\/:*?"<>|\u0000-\u001f]/g, '_')
    .replace(/\s+/g, ' ')
    .replace(/[. ]+$/g, '')
    .slice(0, 80)
  if (!cleaned) return '台号'
  return /^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i.test(cleaned) ? `_${cleaned}` : cleaned
}

export function counterQrFileNames(counters: { uuid: string; name: string; code: string }[]): string[] {
  const nameCounts = new Map<string, number>()
  counters.forEach((counter) => {
    const stem = safeFileStem(counter.name || counter.code || counter.uuid)
    nameCounts.set(stem, (nameCounts.get(stem) ?? 0) + 1)
  })
  return counters.map((counter) => {
    const stem = safeFileStem(counter.name || counter.code || counter.uuid)
    if ((nameCounts.get(stem) ?? 0) === 1) return `${stem}.png`
    const suffix = safeFileStem(counter.code || counter.uuid.slice(0, 8))
    return `${stem}-${suffix}.png`
  })
}
