// ns-ultimate host adapter for read-only nested configuration inspection.
// Composes the generic inspection transport with the host-owned policy. No
// business rules live in integration: this helper owns the entity allowlist,
// the fixed nested paths, and the bounded projection. Network access is a
// single authenticated GET to the resolved detail endpoint.
import {
  assertDetailIdentity,
  fetchInspectedRecord,
  prepareRecordInspection,
  projectInspectedConfiguration,
  unwrapDetailPayload
} from '../../../src/inspect.js'
import recordInspectionPolicy from './inspection-policy.mjs'

export { recordInspectionPolicy }

export function createRecordInspection({ request, getCatalog, isRecordPageAvailable }) {
  if (!request || typeof request.get !== 'function') throw new TypeError('Configuration inspection requires the admin request client.')
  if (typeof getCatalog !== 'function') throw new TypeError('Configuration inspection requires the capability catalog.')
  if (typeof isRecordPageAvailable !== 'function') throw new TypeError('Configuration inspection requires navigation availability checks.')

  return async function inspectRecordConfiguration(input, operations, signal) {
    const catalog = getCatalog(operations)
    const target = prepareRecordInspection(input, { catalog, policy: recordInspectionPolicy, operations })
    if (!isRecordPageAvailable(target.key)) {
      throw new Error('This collection is not available to the current account.')
    }
    if (signal?.aborted) throw new Error('The configuration inspection was cancelled.')
    const startedAt = Date.now()
    const body = await fetchInspectedRecord(request, target.path, { signal })
    if (signal?.aborted) throw new Error('The configuration inspection was cancelled.')
    const record = unwrapDetailPayload(body)
    assertDetailIdentity(record, target.identifier)
    const { configuration, omitted } = projectInspectedConfiguration(record, recordInspectionPolicy[target.key].paths)
    return {
      entity: target.name,
      identifier: target.identifier,
      configuration,
      omitted,
      truncated: false,
      query: {
        entity: target.key,
        identifier: target.identifier,
        durationMs: Date.now() - startedAt,
        completedAt: new Date().toISOString()
      }
    }
  }
}
