/**
 * LEGACY TEST-ONLY capability loader.
 *
 * `catalog.v1.json` is the explicit pre-gate all-ten safety fixture. It is not
 * the production/configured capability snapshot and must not be used to claim
 * current deployment behavior. Generic compiler and browser tests intentionally
 * keep this stable legacy surface; the actual configured export is verified
 * separately against `configured-catalog.v1.json`.
 */
import { readFileSync } from 'node:fs'
import { compileMutationCapabilities } from '../../adapters/ns-ultimate/admin/mutation-capabilities.mjs'

const fixtureUrl = new URL('../fixtures/capabilities/catalog.v1.json', import.meta.url)

export const catalogResponse = Object.freeze(JSON.parse(readFileSync(fixtureUrl, 'utf8')))
export const compiledCatalog = compileMutationCapabilities(catalogResponse)
export const mutationCapabilities = compiledCatalog.capabilities
export const batchCapabilities = compiledCatalog.batchCapabilities

export default mutationCapabilities
