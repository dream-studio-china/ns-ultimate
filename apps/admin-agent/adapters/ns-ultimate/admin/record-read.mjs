import { assertDetailIdentity, fetchInspectedRecord, unwrapDetailPayload } from '../../../src/inspect.js'
import { prepareRecordRead, projectRecordRead } from '../../../src/record-read.js'
import { recordReadPolicy } from './read-policy.mjs'

export { recordReadPolicy }

export function createRecordRead({ request, getCatalog, isRecordPageAvailable }) {
  if (!request || typeof request.get !== 'function') throw new TypeError('Record reading requires the admin request client.')
  if (typeof getCatalog !== 'function') throw new TypeError('Record reading requires the capability catalog.')
  if (typeof isRecordPageAvailable !== 'function') throw new TypeError('Record reading requires navigation availability checks.')

  return async function readRecord(input, operations, signal) {
    const catalog = getCatalog(operations)
    const target = prepareRecordRead(input, { catalog, policy: recordReadPolicy, operations })
    if (!isRecordPageAvailable(target.key)) {
      throw new Error('This collection is not available to the current account.')
    }
    if (signal?.aborted) throw new Error('The record read was cancelled.')
    const startedAt = Date.now()
    const body = await fetchInspectedRecord(request, target.path, { signal, label: 'Record read' })
    if (signal?.aborted) throw new Error('The record read was cancelled.')
    const record = unwrapDetailPayload(body)
    assertDetailIdentity(record, target.identifier)
    const projection = projectRecordRead(record, target.fields)
    return {
      entity: target.name,
      identifier: target.identifier,
      ...projection,
      query: {
        entity: target.key,
        identifier: target.identifier,
        durationMs: Date.now() - startedAt,
        completedAt: new Date().toISOString()
      }
    }
  }
}
