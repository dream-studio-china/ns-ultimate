import inflectFactory from 'i'
import adminConfig from './config'
import request from '@/utils/request'
import router from '@/router'
import { getToken } from '@/utils/auth'
import { chatScopeFromToken } from './agent-chat-scope.mjs'
import CrudSkeletonAdapter from '@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter'
import { resolveRelation } from '@/utils/relation'
import { createSchemaForm } from '@/utils/json-schema-form'
import { scalarComponentBindings } from '../../apps/admin-agent/adapters/ns-ultimate/admin/config-bindings.mjs'
import { resolveFormConfiguration } from '../../apps/admin-agent/adapters/ns-ultimate/admin/form-fields.mjs'
import { createDeleteGuard } from '../../apps/admin-agent/adapters/ns-ultimate/admin/delete-guard.mjs'
import { specialWrite } from '../../apps/admin-agent/adapters/ns-ultimate/admin/special-writes.mjs'
import { createRecordInspection, recordInspectionPolicy } from '../../apps/admin-agent/adapters/ns-ultimate/admin/inspection.mjs'
import { createRecordRead, recordReadPolicy } from '../../apps/admin-agent/adapters/ns-ultimate/admin/record-read.mjs'
import { readCatalogPolicy } from '../../apps/admin-agent/adapters/ns-ultimate/admin/read-policy.mjs'
import { knowledgeArticles } from '../../apps/admin-agent/adapters/ns-ultimate/admin/knowledge/index.mjs'
import { createConfigMutationHost } from '../../apps/admin-agent/src/config-mutation-host.js'
import { buildAdminConfigProjection } from '../../apps/admin-agent/src/admin-config-policy.js'
import { agentMutationPolicy, isAgentActionAllowed } from '../../apps/admin-agent/adapters/ns-ultimate/admin/write-policy.mjs'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { buildCapabilityCatalog, formatAdminReadError, prepareRecordQuery, sanitizeRecordResults } from '../../apps/admin-agent/src/capabilities.js'
import { buildAdminPageCatalog, resolveAdminNavigation } from '../../apps/admin-agent/src/navigation.js'

const inflect = inflectFactory(true)
const defaultPrefix = apiPath(API_PREFIX, 'manage')
const parameterize = name => inflect.dasherize(inflect.underscore(inflect.pluralize(name)))
const adminPages = buildAdminPageCatalog(adminConfig.routes)
const registeredRoutes = () => new Set(router.getRoutes().map(route => route.name).filter(Boolean))
const entityName = (key, config) => typeof config.entity === 'string' ? config.entity : config.entity?.name || key

// Core collection discovery also registers helper UI components that live under
// the collections directory. They are not entity configurations and must never
// become assistant capabilities.
function isEntityConfiguration(config) {
  if (!config || typeof config !== 'object' || Array.isArray(config)) return false
  if (typeof config.setup === 'function' || typeof config.render === 'function') return false
  return Boolean(config.form?.fields || config.list?.query || config.list?.list_filter
    || config.list?.list_display || config.detail?.detail_display)
}
const configuredEntities = Object.entries(adminConfig.entities).filter(([, config]) => isEntityConfiguration(config))

export function createAdminAgentHost() {
  const structures = new Map()
  const listeners = new Set()
  let epoch = 0
  let disposed = false
  let initialized = false
  let initialization = null
  let projection
  let apiOperations = []
  const pathPattern = path => new RegExp(`^${path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/\\{[^/}]+\\}$`)
  const isActionAvailable = (name, action, path) => {
    if (!apiOperations.length || !path) return true
    const known = apiOperations.some(operation => operation.path === path || operation.path.startsWith(`${path}/`))
    if (!known) return true
    const item = pathPattern(path)
    if (action === 'create') return apiOperations.some(operation => operation.path === path && operation.method === 'POST')
    if (action === 'update') return apiOperations.some(operation => item.test(operation.path) && ['PUT', 'PATCH'].includes(operation.method))
    if (action === 'delete') return apiOperations.some(operation => item.test(operation.path) && operation.method === 'DELETE')
    return true
  }
  const accessibleNavigation = () => {
    const routes = registeredRoutes()
    return adminPages.filter(page => routes.has(page.key)).map(page => {
      const entry = configuredEntities.find(([key, config]) => `${entityName(key, config)}List` === page.key)
      return { ...page, entity: entry ? entityName(...entry) : undefined, entityKey: entry?.[0] }
    })
  }
  const getCatalog = operations => {
    return buildCapabilityCatalog(Object.fromEntries(configuredEntities), operations, { parameterize, defaultPrefix, readPolicy: readCatalogPolicy })
  }
  const isRecordPageAvailable = entity => accessibleNavigation().some(page => page.entity === entity || page.entityKey === entity)
  const inspectRecordConfiguration = createRecordInspection({ request, getCatalog, isRecordPageAvailable })
  const readRecord = createRecordRead({ request, getCatalog, isRecordPageAvailable })
  const buildProjection = () => {
    const entities = Object.fromEntries(configuredEntities.map(([key, config]) => [
      key, resolveFormConfiguration(config, structures.get(key), { resolveRelation, entities: Object.fromEntries(configuredEntities) })
    ]))
    return buildAdminConfigProjection({ ...adminConfig, entities }, {
      defaultPrefix, parameterize, componentBindings: scalarComponentBindings,
      schemaFormResolver: createSchemaForm, routeAvailability: registeredRoutes()
    })
  }
  const refreshProjection = () => {
    if (disposed) throw new Error('The admin session ended.')
    const next = buildProjection()
    const previousRevision = projection?.revision ?? null
    projection = next
    if (previousRevision !== next.revision) {
      const event = { epoch: ++epoch, revision: next.revision, previousRevision, error: '' }
      for (const listener of [...listeners]) { try { listener(event) } catch { /* Observers must not interrupt CRUD. */ } }
    }
    return projection
  }
  refreshProjection()
  const mutations = createConfigMutationHost({ request, getProjection: refreshProjection, policy: agentMutationPolicy,
    beforeDelete: createDeleteGuard({ request, getProjection: refreshProjection }), specialWrite, isActionAvailable })
  async function initializeAgentCapabilities(signal, operations) {
    if (Array.isArray(operations)) apiOperations = operations
    if (!initialization) initialization = (async() => {
      const entries = configuredEntities.filter(([key, config]) => !structures.has(key) && isRecordPageAvailable(key)
        && ['create', 'update'].some(action => isAgentActionAllowed(entityName(key, config), action)))
      let index = 0
      await Promise.all(Array.from({ length: Math.min(4, entries.length) }, async() => {
        while (index < entries.length && !disposed && !signal?.aborted) {
          const [key, config] = entries[index++]
          try {
            const structure = await new CrudSkeletonAdapter(config.entity || key).structure()
            if (!disposed && !signal?.aborted) structures.set(key, structure)
          } catch { /* A missing structure disables form writes, not read tools. */ }
        }
      }))
      if (disposed || signal?.aborted) throw new Error('Capability initialization was cancelled.')
      initialized = true
      refreshProjection()
    })().finally(() => { initialization = null })
    await initialization
    return { capabilities: mutations.getWriteCapabilities(), batchCapabilities: mutations.getBatchCreateCapabilities() }
  }
  return {
    ...mutations,
    // Local partition only, not authorization. Never persist authentication tokens.
    getChatHistoryScope() { return chatScopeFromToken(getToken()) },
    initializeAgentCapabilities,
    getCapabilityStatus() { return { status: initialized ? 'ready' : 'idle', error: '', revision: projection.revision } },
    getCapabilityRevision() { return refreshProjection().revision },
    getAdminConfigProjection() { return refreshProjection() },
    onCapabilityChange(listener) { listeners.add(listener); return () => listeners.delete(listener) },
    getCatalog,
    getMainAreaBounds() { return document.querySelector('.app-main')?.getBoundingClientRect() ?? null },
    getInspectionPolicy() { return recordInspectionPolicy },
    inspectRecordConfiguration,
    getRecordReadPolicy() { return recordReadPolicy },
    readRecord,
    getKnowledgeArticles() { return knowledgeArticles },
    getNavigationCatalog: accessibleNavigation,
    getManualDeleteNavigationCatalog() {
      const current = refreshProjection()
      return accessibleNavigation().filter(page => page.entity
        && current.entities.find(item => item.name === page.entity)?.actions.delete.configured
        && !isAgentActionAllowed(page.entity, 'delete'))
    },
    getAdminNavigationLink(pageKey, identifier) {
      const page = accessibleNavigation().find(item => item.key === pageKey)
      if (!page) return null
      let safeIdentifier = null
      if (identifier !== undefined && identifier !== null && identifier !== '') {
        try { resolveAdminNavigation(accessibleNavigation(), pageKey, identifier) } catch { return null }
        safeIdentifier = String(identifier)
      }
      const detail = safeIdentifier && page.entity && registeredRoutes().has(`${page.entity}Detail`)
      const route = router.resolve(detail ? { name: `${page.entity}Detail`, params: { id: safeIdentifier } } : { name: page.key })
      if (!route.matched.length || !route.href.startsWith('/') || route.href.startsWith('//')) return null
      return { href: route.href, view: detail ? 'detail' : 'list', entity: page.entity ?? null }
    },
    async navigateAdminPage(pageKey, identifier) {
      const target = resolveAdminNavigation(accessibleNavigation(), pageKey, identifier)
      if (!router.resolve(target.path).matched.length) throw new Error('The admin page is not currently available.')
      await router.push(target.path)
      if (router.currentRoute.value.path !== target.path) throw new Error('The admin page is not available for the current account.')
      return { title: target.title, identifier: target.identifier || null }
    },
    async queryRecords(input, operations, signal) {
      const catalog = getCatalog(operations)
      const query = prepareRecordQuery(input, catalog)
      if (signal?.aborted) throw new Error('The read query was cancelled.')
      let response
      try { response = await request.get(query.path, { params: query.params, signal, timeout: 60000 }) }
      catch (error) { throw new Error(formatAdminReadError(error)) }
      if (signal?.aborted) throw new Error('The read query was cancelled.')
      return sanitizeRecordResults(response?.data, query, catalog)
    },
    dispose() { disposed = true; structures.clear(); listeners.clear(); mutations.dispose() }
  }
}
