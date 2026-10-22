import inflectFactory from 'i'
import adminConfig from './config'
import request from '@/utils/request'
import router from '@/router'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { buildCapabilityCatalog, formatAdminReadError, prepareRecordQuery, sanitizeRecordResults } from '../../agent/src/capabilities.js'
import { buildAdminPageCatalog, resolveAdminNavigation } from '../../agent/src/navigation.js'

const inflect = inflectFactory(true)
const defaultPrefix = apiPath(API_PREFIX, 'manage')
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

const parameterize = name => inflect.dasherize(inflect.underscore(inflect.pluralize(name)))

export function createAdminAgentHost() {
  const getCatalog = operations => buildCapabilityCatalog(adminConfig.entities, operations, { parameterize, defaultPrefix })

  return {
    getCatalog,
    getNavigationCatalog() { return accessibleNavigation() },
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
      }, 20000)
      if (signal?.aborted) abortFromCaller()
      // Use the admin's authenticated GET client so existing session refresh,
      // locale, API base URL, and backend authorization remain authoritative.
      let response
      try {
        response = await request.get(query.path, { params: query.params, signal: controller.signal })
      } catch (error) {
        if (timedOut) throw new Error('The read query timed out after 20 seconds.')
        throw new Error(formatAdminReadError(error))
      } finally {
        clearTimeout(timeout)
        signal?.removeEventListener('abort', abortFromCaller)
      }
      if (signal?.aborted) throw new Error('The read query was cancelled.')
      const records = response?.data
      return sanitizeRecordResults(records, query, catalog)
    }
  }
}
