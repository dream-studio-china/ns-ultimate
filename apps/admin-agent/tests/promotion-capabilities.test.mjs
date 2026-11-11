import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import { buildAdminConfigProjection } from '../src/admin-config-policy.js'
import { createConfigMutationHost } from '../src/config-mutation-host.js'
import { createAssistantTools } from '../src/tools.js'
import {
  agentMutationPolicy,
  isAgentActionAllowed,
  isAgentFieldAllowed
} from '../adapters/ns-ultimate/admin/write-policy.mjs'

const repository = new URL('../../../', import.meta.url)

const templateOptions = [
  'full_reduction', 'discount', 'member_discount', 'nth_discount', 'gift', 'free_shipping'
]

function fixture() {
  return {
    entities: {
      Promotion: {
        entity: { name: 'Promotion', plural: 'promotions' },
        form: { fields: [
          { property: 'name', required: true },
          { property: 'template', required: true, relation: { target: 'PromotionTemplate', valueKey: 'id' } },
          { property: 'storeCode' },
          { property: 'enabled', type: 'boolean', default_value: false },
          { property: 'startTime', type: 'datetime' },
          { property: 'endTime', type: 'datetime' },
          { property: 'config', type: 'json' },
          { property: 'conflictMode', type: 'select', default_value: 'stackable', type_options: { options: [
            { value: 'stackable', label: 'Stackable' },
            { value: 'exclusive', label: 'Exclusive' },
            { value: 'lock_item', label: 'Lock item' },
            { value: 'best_price', label: 'Best price' }
          ] } }
        ] },
        list: { disabled_actions: [] }
      },
      PromotionTemplate: {
        entity: { name: 'PromotionTemplate', plural: 'promotion-templates' },
        form: { fields: [
          { property: 'name', required: true },
          { property: 'type', type: 'select', required: true, default_value: 'full_reduction', type_options: { options: templateOptions.map(value => ({ value })) } },
          { property: 'phase', type: 'select', default_value: 0, type_options: { options: [{ value: 0 }, { value: 1 }] } },
          { property: 'enabled', type: 'boolean', default_value: false },
          { property: 'dsl', type: 'code', required: true },
          { property: 'fields', type: 'json' }
        ] },
        list: { disabled_actions: [] }
      }
    },
    routes: [
      { path: '/promotion/list', name: 'PromotionList' },
      { path: '/promotion-template/list', name: 'PromotionTemplateList' }
    ]
  }
}

function host() {
  const projection = buildAdminConfigProjection(fixture())
  return createConfigMutationHost({
    getProjection: () => projection,
    request: { get: async() => ({ data: {} }) },
    policy: agentMutationPolicy
  })
}

test('promotion writes are configured create/update capabilities without delete', () => {
  assert.equal(isAgentActionAllowed('Promotion', 'create'), true)
  assert.equal(isAgentActionAllowed('Promotion', 'update'), true)
  assert.equal(isAgentActionAllowed('Promotion', 'delete'), false)
  assert.equal(isAgentActionAllowed('PromotionTemplate', 'create'), true)
  assert.equal(isAgentActionAllowed('PromotionTemplate', 'update'), true)
  assert.equal(isAgentActionAllowed('PromotionTemplate', 'delete'), false)
  for (const property of ['name', 'template', 'config', 'conflictMode']) {
    assert.equal(isAgentFieldAllowed('Promotion', property, 'create'), true, property)
  }
  for (const property of ['name', 'type', 'phase', 'dsl']) {
    assert.equal(isAgentFieldAllowed('PromotionTemplate', property, 'create'), true, property)
  }
})

test('promotion capability exposes the template relation, template-defined config and four conflict modes', () => {
  const capabilities = host().getWriteCapabilities()
  const promotion = capabilities.find(item => item.entity === 'Promotion')
  assert.deepEqual(promotion.actions, ['create', 'update'])
  const create = promotion.actionFields.create
  assert.equal(create.template.requiredOnCreate, true)
  assert.match(create.template.description, /relation/)
  assert.match(create.template.description, /PromotionTemplate id/)
  assert.match(create.config.description, /object/)
  assert.match(create.conflictMode.description, /\["stackable","exclusive","lock_item","best_price"\]/)
  assert.deepEqual(capabilities.find(item => item.entity === 'PromotionTemplate').actions, ['create', 'update'])
})

test('prepares a campaign with a real template id and rejects invalid or forged values', async() => {
  const mutationHost = host()
  const response = await mutationHost.prepareRecordChange({
    entity: 'Promotion',
    action: 'create',
    changes: {
      name: 'Autumn campaign',
      template: 4,
      storeCode: '',
      enabled: true,
      startTime: '2026-11-12T10:00:00+08:00',
      conflictMode: 'best_price',
      config: { rate: 90, specification_ids: [12] }
    }
  })
  const byProperty = Object.fromEntries(response.differences.map(item => [item.property, item]))
  assert.equal(byProperty.template.after, 4)
  assert.equal(byProperty.conflictMode.after, 'best_price')
  assert.deepEqual(byProperty.config.after, { rate: 90, specification_ids: [12] })
  assert.equal(response.saved, false)
  assert.equal(Object.hasOwn(response, 'submission'), false)

  await assert.rejects(mutationHost.prepareRecordChange({
    entity: 'Promotion', action: 'create', changes: { name: 'Missing template' }
  }), /Missing required field: template/)
  await assert.rejects(mutationHost.prepareRecordChange({
    entity: 'Promotion', action: 'create',
    changes: { name: 'Bad mode', template: 4, conflictMode: 'priority' }
  }), /Invalid option/)
  // Domain validation is explicitly supplied by the composed host, not a
  // duplicated business allowlist in the generic CRUD transport.
  await assert.rejects(mutationHost.prepareRecordChange({
    entity: 'Promotion', action: 'create',
    changes: { name: 'Bad config', template: 4, config: { amount: Number.NaN } }
  }), /non-empty JSON/)
})

test('promotion template capability exposes supported types and phases only', () => {
  const template = host().getWriteCapabilities().find(item => item.entity === 'PromotionTemplate')
  const create = template.actionFields.create
  assert.match(create.type.description, /\["full_reduction","discount","member_discount","nth_discount","gift","free_shipping"\]/)
  assert.match(create.phase.description, /\[0,1\]/)
  assert.equal(create.dsl.type, 'json')
  assert.equal(create.dsl.requiredOnCreate, true)
})

test('the model tool description carries promotion entities and fields', () => {
  const tools = createAssistantTools([], [], {}, host().getWriteCapabilities())
  const description = tools.find(tool => tool.function.name === 'prepare_record_change').function.description
  assert.match(description, /"entity":"Promotion"/)
  assert.match(description, /"entity":"PromotionTemplate"/)
  assert.match(description, /"template"/)
  assert.match(description, /"dsl"/)
})

test('the effective admin overrides carry promotion fields without a fixed parameter whitelist', () => {
  const promotion = readFileSync(new URL('business/admin/config/overrides/promotion/promotion.js', repository), 'utf8')
  assert.match(promotion, /property: 'template'/)
  assert.match(promotion, /relation: \{ entity: 'PromotionTemplate' \}/)
  assert.doesNotMatch(promotion, /PromotionConfigSchema/)
  assert.match(promotion, /value: 'lock_item'/)
  assert.match(promotion, /value: 'best_price'/)

  const template = readFileSync(new URL('business/admin/config/overrides/promotion/promotion-template.js', repository), 'utf8')
  assert.match(template, /type: 'select'/)
  assert.match(template, /property: 'phase',\s+type: 'select'/s)
  for (const value of templateOptions) assert.match(template, new RegExp(`value: '${value}'`))
  assert.doesNotMatch(template, /value: 'tiered'/)

  const index = readFileSync(new URL('business/admin/config/index.js', repository), 'utf8')
  assert.match(index, /import Promotion from '\.\/overrides\/promotion\/promotion\.js'/)
  assert.match(index, /import PromotionTemplate from '\.\/overrides\/promotion\/promotion-template\.js'/)
  assert.match(index, /replace: \{[^\n]*Promotion, PromotionTemplate/)

  assert.match(template, /property: 'fields', type: 'json'/)
})
