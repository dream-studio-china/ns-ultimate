import inflectFactory from 'i'
import adminConfig from './config'
import request from '@/utils/request'
import { API_PREFIX, apiPath } from '@/api/prefix'
import { buildCapabilityCatalog, formatAdminReadError, prepareRecordQuery, sanitizeRecordResults } from '../../agent/src/capabilities.js'

const inflect = inflectFactory(true)
const defaultPrefix = apiPath(API_PREFIX, 'manage')

const parameterize = name => inflect.dasherize(inflect.underscore(inflect.pluralize(name)))

export function createAdminAgentHost() {
  const getCatalog = operations => buildCapabilityCatalog(adminConfig.entities, operations, { parameterize, defaultPrefix })

  return {
    getCatalog,
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
