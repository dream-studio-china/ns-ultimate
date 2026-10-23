import assert from 'node:assert/strict'
import test from 'node:test'
import { buildAdminConfigProjection } from '../src/admin-config-policy.js'
import {
  buildAgentManifest,
  loadEffectiveAdminConfig
} from '../../../integration/admin/export-agent-manifest.mjs'

test('actual core resolver proves zero writable root fields are omitted from projection and manifest', async() => {
  const loaded = await loadEffectiveAdminConfig({ logLevel: 'silent' })
  const metadataSchema = {
    type: 'object',
    properties: {
      featured: { type: 'boolean' },
      layout: { type: 'string' }
    }
  }
  const fixture = {
    entities: {
      Content: {
        entity: { name: 'Content', plural: 'contents' },
        form: {
          fields: [
            {
              property: 'metadata',
              type: 'json_schema',
              type_options: {
                schema: metadataSchema,
                fields: [
                  { property: 'featured', field_options: { disabled: true } },
                  { property: 'layout', type_options: { readonly: true } }
                ]
              }
            },
            { property: 'rawJson', type: 'json' }
          ]
        },
        list: { disabled_actions: [] }
      }
    },
    routes: [{
      path: '/content',
      name: 'ContentManage',
      children: [{ path: '/content/list', name: 'ContentList' }]
    }]
  }

  const projection = buildAdminConfigProjection(fixture, {
    defaultPrefix: loaded.defaultPrefix,
    parameterize: loaded.parameterize,
    schemaFormResolver: loaded.schemaFormResolver
  })
  const content = projection.entities.find(entity => entity.name === 'Content')
  assert.deepEqual(content.actions.create.fields.map(field => field.property), ['rawJson'])
  assert.deepEqual(content.actions.update.fields.map(field => field.property), ['rawJson'])
  assert.equal(JSON.stringify(content.actions).includes('"nestedPaths":[]'), false)

  const manifest = buildAgentManifest(fixture, {
    defaultPrefix: loaded.defaultPrefix,
    parameterize: loaded.parameterize,
    projector: buildAdminConfigProjection,
    semanticBindings: {},
    componentBindings: {},
    schemaFormResolver: loaded.schemaFormResolver
  })
  const manifestContent = manifest.entities.find(entity => entity.name === 'Content')
  assert.deepEqual(manifestContent.actions.create.fields.map(field => field.property), ['rawJson'])
  assert.deepEqual(manifestContent.actions.update.fields.map(field => field.property), ['rawJson'])
  assert.equal(JSON.stringify(manifestContent).includes('"nestedPaths":[]'), false)
})
