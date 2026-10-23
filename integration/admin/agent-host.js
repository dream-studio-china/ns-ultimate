import inflectFactory from 'i'
import adminConfig from './config'
import request from '@/utils/request'
import router from '@/router'
import { createNsUltimateMutationRuntime } from '../../apps/admin-agent/adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import { scalarComponentBindings, semanticCapabilityBindings } from '../../apps/admin-agent/adapters/ns-ultimate/admin/config-bindings.mjs'
import { createRecordInspection, recordInspectionPolicy } from '../../apps/admin-agent/adapters/ns-ultimate/admin/inspection.mjs'
import { knowledgeArticles } from '../../apps/admin-agent/adapters/ns-ultimate/admin/knowledge/index.mjs'
import { createMutationHost } from '../../apps/admin-agent/src/mutation-host.js'
import { createBatchMutationHost } from '../../apps/admin-agent/src/batch-mutation-host.js'
import { AGENT_DELETE_ALLOWLIST } from '../../apps/admin-agent/src/write-policy.js'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { createSchemaForm } from '@/utils/json-schema-form'
import { buildCapabilityCatalog, formatAdminReadError, prepareRecordQuery, sanitizeRecordResults } from '../../apps/admin-agent/src/capabilities.js'
import { buildAdminPageCatalog, resolveAdminNavigation } from '../../apps/admin-agent/src/navigation.js'
import {
  buildAdminConfigProjection,
  combineCapabilityRevision,
  createAdminConfigCapabilitySource,
  ensureFreshCommitCapability
} from '../../apps/admin-agent/src/admin-config-policy.js'

const inflect = inflectFactory(true)
const defaultPrefix = apiPath(API_PREFIX, 'manage')
const parameterize = name => inflect.dasherize(inflect.underscore(inflect.pluralize(name)))

const adminPages = buildAdminPageCatalog(adminConfig.routes)
const adminNavigation = adminPages.map(page => {
  const entity = Object.keys(adminConfig.entities).find(key => {
    const config = adminConfig.entities[key]
    return (typeof config?.entity?.name === 'string' ? config.entity.name : key) === page.key.slice(0, -'List'.length)
  })
  return { key: page.key, title: page.title, ...(entity ? { entity } : {}) }
})
const registeredRoutes = () => new Set(router.getRoutes().map(route => route.name).filter(Boolean))
const accessibleNavigation = () => {
  const names = registeredRoutes()
  return adminNavigation.filter(page => names.has(page.key))
}

export function createAdminAgentHost() {
  const getCatalog = operations => buildCapabilityCatalog(adminConfig.entities, operations, { parameterize, defaultPrefix })
  const inspectRecordConfiguration = createRecordInspection({
    request,
    getCatalog,
    isRecordPageAvailable: entity => accessibleNavigation().some(page => page.entity === entity)
  })

  const buildProjection = () => buildAdminConfigProjection(adminConfig, {
    defaultPrefix,
    parameterize,
    semanticBindings: semanticCapabilityBindings,
    componentBindings: scalarComponentBindings,
    schemaFormResolver: createSchemaForm,
    routeAvailability: registeredRoutes()
  })
  let projection = buildProjection()
  let projectionGeneration = 0
  let generationRevision = projection.revision
  const refreshProjection = () => {
    const next = buildProjection()
    projection = next
    if (next.revision !== generationRevision) {
      generationRevision = next.revision
      projectionGeneration += 1
    }
    return projection
  }

  // The runtime catalog is scoped to this host instance (one admin session).
  // It is initialized before model tool generation and never shared globally.
  const capabilityRuntime = createNsUltimateMutationRuntime({
    request,
    path: `${defaultPrefix}/agent/capabilities`
  })

  // One opaque effective identity is shared by the invalidation event, the
  // capability source used by transports, and their in-flight snapshot guard.
  // Runtime epoch catches failure/recovery ABA even when the catalog hash
  // returns to the same value; projection generation changes only with the
  // effective config projection or a failure event, never on a same-revision
  // forced refresh.
  const effectiveIdentity = () => combineCapabilityRevision(
    capabilityRuntime.revision,
    projection.revision,
    capabilityRuntime.epoch,
    projectionGeneration
  )
  const capabilityListeners = new Set()
  let publishedIdentity
  let publishedError
  let publishedEpoch = 0
  const publishEffectiveCapability = ({ error = '' } = {}) => {
    if (error && error !== publishedError) projectionGeneration += 1
    const identity = effectiveIdentity()
    if (identity === publishedIdentity && error === publishedError) return
    const previousRevision = publishedIdentity ?? null
    publishedIdentity = identity
    publishedError = error
    publishedEpoch += 1
    const event = Object.freeze({ epoch: publishedEpoch, revision: identity, previousRevision, error })
    for (const listener of [...capabilityListeners]) {
      try { listener(event) } catch { /* UI listeners must not break the transport. */ }
    }
  }
  const unsubscribeRuntime = capabilityRuntime.subscribe(event => {
    publishEffectiveCapability({ error: event.error || '' })
  })

  // Tools and transports share these memoized, narrowed instances. A changed
  // effective identity produces a new instance set.
  const singleSource = createAdminConfigCapabilitySource({
    getProjection: () => projection,
    getCapabilities: () => capabilityRuntime.current?.capabilities ?? [],
    getRuntimeRevision: () => capabilityRuntime.revision,
    getRuntimeEpoch: () => capabilityRuntime.epoch,
    getProjectionEpoch: () => projectionGeneration
  })
  const batchSource = createAdminConfigCapabilitySource({
    getProjection: () => projection,
    getCapabilities: () => capabilityRuntime.current?.batchCapabilities ?? [],
    getRuntimeRevision: () => capabilityRuntime.revision,
    getRuntimeEpoch: () => capabilityRuntime.epoch,
    getProjectionEpoch: () => projectionGeneration
  })
  const mutationHost = createMutationHost(request, `${defaultPrefix}/agent/operations`, singleSource)
  const batchMutationHost = createBatchMutationHost(request, `${defaultPrefix}/agent/batch-operations`, batchSource)
  const ensureCapabilities = async signal => {
    // Configuration may be replaced by the host while a session is open. A
    // policy-bearing prepare/restore therefore refreshes the projection before
    // using the cached runtime snapshot; config-only revisions still emit the
    // same invalidation boundary as a server revision.
    refreshProjection()
    const current = await capabilityRuntime.ensure({ signal })
    publishEffectiveCapability({ error: capabilityRuntime.status.error })
    return current
  }
  const refreshForCommit = signal => ensureFreshCommitCapability({
    getIdentity: effectiveIdentity,
    refreshProjection,
    refreshCatalog: requestSignal => capabilityRuntime.refresh({ signal: requestSignal }),
    signal,
    onRefreshed: () => publishEffectiveCapability({ error: capabilityRuntime.status.error })
  })

  return {
    ...mutationHost,
    ...batchMutationHost,

    async commitRecordChange(operationId, signal) {
      // Force a fresh server catalog and effective projection before the POST.
      // A stale/changed identity is a definitive pre-send conflict, never an
      // ambiguous unknown outcome. Status/history methods remain untouched.
      await refreshForCommit(signal)
      return mutationHost.commitRecordChange(operationId, signal)
    },
    async commitRecordBatch(operationId, signal) {
      await refreshForCommit(signal)
      return batchMutationHost.commitRecordBatch(operationId, signal)
    },

    async initializeAgentCapabilities(signal) {
      // Rebuild the effective-config projection before re-reading the
      // account-filtered runtime revision. Both revisions participate in tool
      // derivation and invalidation; the backend runtime remains the current
      // execution/security authority.
      refreshProjection()
      await capabilityRuntime.refresh({ signal })
      publishEffectiveCapability({ error: capabilityRuntime.status.error })
      return capabilityRuntime.current
    },
    getCapabilityStatus() {
      return {
        ...capabilityRuntime.status,
        projectionRevision: projection.revision,
        projectionGeneration
      }
    },
    getCapabilityRevision() { return effectiveIdentity() },
    getAdminConfigProjection() { return projection },
    onCapabilityChange(listener) {
      if (typeof listener !== 'function') return () => {}
      capabilityListeners.add(listener)
      return () => capabilityListeners.delete(listener)
    },

    // Both preparation and preview recovery require the current compiled
    // catalog. If initialization failed, these fail closed rather than
    // falling back to a static policy.
    async prepareRecordChange(input, signal) {
      await ensureCapabilities(signal)
      return mutationHost.prepareRecordChange(input, signal)
    },
    async restoreRecordChange(operationId, signal) {
      await ensureCapabilities(signal)
      return mutationHost.restoreRecordChange(operationId, signal)
    },
    async prepareRecordBatch(input, signal) {
      await ensureCapabilities(signal)
      return batchMutationHost.prepareRecordBatch(input, signal)
    },
    async restoreRecordBatch(operationId, signal) {
      await ensureCapabilities(signal)
      return batchMutationHost.restoreRecordBatch(operationId, signal)
    },

    getCatalog,
    getInspectionPolicy() { return recordInspectionPolicy },
    inspectRecordConfiguration,
    getKnowledgeArticles() { return knowledgeArticles },
    getNavigationCatalog() { return accessibleNavigation() },
    getManualDeleteNavigationCatalog() {
      return accessibleNavigation().filter(page => {
        if (!page.entity) return false
        const entity = projection.entities.find(item => item.name === page.entity)
        if (entity?.actions?.delete?.configured !== true) return false
        const capability = mutationHost.getWriteCapabilities().find(item => item.entity === page.entity)
        const assistantCanDelete = AGENT_DELETE_ALLOWLIST.includes(page.entity) && capability?.actions?.includes('delete')
        return !assistantCanDelete
      })
    },
    getAdminNavigationLink(pageKey, identifier) {
      const page = accessibleNavigation().find(item => item.key === pageKey)
      if (!page) return null
      let safeIdentifier = null
      if (identifier !== undefined && identifier !== null && identifier !== '') {
        try { resolveAdminNavigation(accessibleNavigation(), pageKey, identifier) }
        catch { return null }
        safeIdentifier = String(identifier)
      }
      const names = registeredRoutes()
      if (safeIdentifier && page.entity && names.has(`${page.entity}Detail`)) {
        const detail = router.resolve({ name: `${page.entity}Detail`, params: { id: safeIdentifier } })
        if (detail.matched.length && detail.href.startsWith('/') && !detail.href.startsWith('//')) {
          return { href: detail.href, view: 'detail', entity: page.entity }
        }
      }
      const list = router.resolve({ name: page.key })
      if (!list.matched.length || !list.href.startsWith('/') || list.href.startsWith('//')) return null
      return { href: list.href, view: 'list', entity: page.entity ?? null }
    },
    async navigateAdminPage(pageKey, identifier) {
      const target = resolveAdminNavigation(adminPages, pageKey, identifier)
      const route = router.resolve(target.path)
      if (!route.matched.length) throw new Error('The admin page is not currently available.')
      await router.push(target.path)
      if (router.currentRoute.value.path !== target.path) throw new Error('The admin page is not available for the current account.')
      return { title: target.title, identifier: target.identifier || null }
    },
    async queryRecords(input, operations, signal) {
      const catalog = getCatalog(operations)
      const query = prepareRecordQuery(input, catalog)
      if (!accessibleNavigation().some(page => page.entity === input.entity)) {
        throw new Error('This collection is not available to the current account.')
      }
      if (signal?.aborted) throw new Error('The read query was cancelled.')
      const controller = new AbortController()
      let timedOut = false
      const abortFromCaller = () => controller.abort(signal?.reason)
      signal?.addEventListener('abort', abortFromCaller, { once: true })
      const timeout = setTimeout(() => {
        timedOut = true
        controller.abort()
      }, 60000)
      if (signal?.aborted) abortFromCaller()
      // Use the admin's authenticated GET client so existing session refresh,
      // locale, API base URL, and backend authorization remain authoritative.
      let response
      try {
        response = await request.get(query.path, { params: query.params, signal: controller.signal, timeout: 60000 })
      } catch (error) {
        if (timedOut) throw new Error('The read query timed out after 60 seconds.')
        throw new Error(formatAdminReadError(error))
      } finally {
        clearTimeout(timeout)
        signal?.removeEventListener('abort', abortFromCaller)
      }
      if (signal?.aborted) throw new Error('The read query was cancelled.')
      const records = response?.data
      return sanitizeRecordResults(records, query, catalog)
    },
    dispose() {
      unsubscribeRuntime()
      capabilityListeners.clear()
      capabilityRuntime.dispose()
    }
  }
}
