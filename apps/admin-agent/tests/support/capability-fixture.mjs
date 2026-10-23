/**
 * Test-only capability loader.
 *
 * Tests must exercise the real runtime compiler against the canonical server
 * export snapshot rather than importing a static policy table from production
 * source. Node tests read the fixture file directly so the JSON stays the only
 * copy of the descriptor set.
 */
import { readFileSync } from 'node:fs'
import { compileMutationCapabilities } from '../../adapters/ns-ultimate/admin/mutation-capabilities.mjs'

const fixtureUrl = new URL('../fixtures/capabilities/catalog.v1.json', import.meta.url)

export const catalogResponse = Object.freeze(JSON.parse(readFileSync(fixtureUrl, 'utf8')))
export const compiledCatalog = compileMutationCapabilities(catalogResponse)
export const mutationCapabilities = compiledCatalog.capabilities
export const batchCapabilities = compiledCatalog.batchCapabilities

export default mutationCapabilities
