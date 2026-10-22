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

const parameterize = name => inflect.dasherize(inflect.underscore(inflect.pluralize(name)))

export function createAdminAgentHost() {
  const getCatalog = operations => buildCapabilityCatalog(adminConfig.entities, operations, { parameterize, defaultPrefix })

  return {
    getCatalog,
    getNavigationCatalog() { return adminPages.map(({ key, title }) => ({ key, title })) },
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
      if (signal?.aborted) throw new Error('The read query was cancelled.')
      // Use the admin's authenticated GET client so existing session refresh,
      // locale, API base URL, and backend authorization remain authoritative.
      let response
      try {
        response = await request.get(query.path, { params: query.params, signal })
      } catch (error) {
        throw new Error(formatAdminReadError(error))
      }
      if (signal?.aborted) throw new Error('The read query was cancelled.')
      const records = response?.data
      return sanitizeRecordResults(records, query, catalog)
    }
  }
}
