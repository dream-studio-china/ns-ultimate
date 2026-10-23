import assert from 'node:assert/strict'
import test from 'node:test'
import { resolveRelation } from '../../../core/crud-admin/src/utils/relation-descriptor.js'
import { resolveFormConfiguration } from '../adapters/ns-ultimate/admin/form-fields.mjs'
import { buildAdminConfigProjection } from '../src/admin-config-policy.js'
import { configuredCapabilities } from '../src/config-mutation-host.js'
import { agentMutationPolicy } from '../adapters/ns-ultimate/admin/write-policy.mjs'

const structure = {
  id: { metadata: { type: 'integer', nullable: false } },
  title: { metadata: { type: 'string', nullable: false } },
  enabled: { metadata: { type: 'boolean', nullable: false } },
  category: { metadata: { type: 'ManyToOne', targetEntity: 'App\\Common\\Entity\\Category', nullable: true } },
  tags: { metadata: { type: 'ManyToMany', targetEntity: 'App\\Common\\Entity\\Tag' } },
  metadata: { metadata: { type: 'json', nullable: true } }
}
const resolve = config => resolveFormConfiguration(config, structure, { resolveRelation, entities: {} })

test('configured fields use real admin metadata and relation resolution, not duplicate field tables', () => {
  const config = resolve({ form: { fields: ['title', 'enabled', 'category', 'tags', 'metadata'] }, list: {} })
  assert.equal(config.form.fields[1].type, 'boolean')
  assert.equal(config.form.fields[0].required, true)
  assert.deepEqual(config.form.fields[2].relation, { target: 'Category', valueKey: 'id', multiple: false })
  assert.deepEqual(config.form.fields[3].relation, { target: 'Tag', valueKey: 'id', multiple: true })
  assert.equal(config.form.fields[4].type, 'json')
})

test('wildcard forms expand only trusted metadata and preserve explicit hidden/read-only overrides', () => {
  const config = resolve({ form: { fields: [{ property: 'title', readOnly: true }, '__all__'] }, list: {} })
  assert.equal(config.form.fields.some(field => field.property === 'id'), false)
  assert.equal(config.form.fields.filter(field => field.property === 'title').length, 1)
  const projection = buildAdminConfigProjection({ entities: { Content: config }, routes: [{ name: 'ContentList', path: '/contents/list' }] })
  const cap = configuredCapabilities(projection, agentMutationPolicy)[0]
  assert.equal(Object.hasOwn(cap.actionFields.update, 'title'), false)
  assert.ok(cap.actionFields.update.metadata)
})

test('missing metadata disables form writes; an explicit scalar type and required flag override metadata', () => {
  assert.equal(resolveFormConfiguration({ form: { fields: ['title'] } }, null, { resolveRelation, entities: {} }).form, undefined)
  const config = resolve({ form: { fields: [{ property: 'title', type: 'text', required: false }] } })
  assert.equal(config.form.fields[0].type, 'text')
  assert.equal(config.form.fields[0].required, false)
})
