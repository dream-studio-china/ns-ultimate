import assert from 'node:assert/strict'
import test from 'node:test'
import { readFileSync } from 'node:fs'
import {
  buildAdminConfigProjection,
  collectNestedPaths,
  combineCapabilityRevision,
  createAdminConfigCapabilitySource,
  ensureFreshCommitCapability,
  intersectCapabilityCatalog
} from '../src/admin-config-policy.js'
import { createCapabilityCatalogRuntime } from '../src/capability-catalog.js'
import { createMutationHost } from '../src/mutation-host.js'
import { compileMutationCapabilities } from '../adapters/ns-ultimate/admin/mutation-capabilities.mjs'
import {
  scalarComponentBindings,
  semanticCapabilityBindings
} from '../adapters/ns-ultimate/admin/config-bindings.mjs'
import { catalogResponse } from './support/capability-fixture.mjs'

const REVISION = 'a'.repeat(64)
const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'

function stringRule(maxLength = 100, extra = {}) {
  return { type: 'string', maxLength, ...extra }
}

function catalog(capabilities, batchCapabilities = []) {
  return compileMutationCapabilities({
    schemaVersion: 1,
    revision: REVISION,
    capabilities,
    batchCapabilities
  })
}

function descriptor(entity, actions, actionFields, fields) {
  return {
    entity,
    actions,
    actionFields,
    fields: fields || Object.assign({}, ...Object.values(actionFields))
  }
}

function entityConfig(name, { fields, disabled, detailDisabled, extra = {} } = {}) {
  return {
    entity: { name, plural: name.toLowerCase() + 's' },
    ...(fields === undefined ? {} : { form: { fields } }),
    list: { disabled_actions: disabled || [] },
    ...(detailDisabled ? { detail: { disabled_actions: detailDisabled } } : {}),
    ...extra
  }
}

function route(name) {
  return { path: `/${name.toLowerCase()}/list`, name: `${name}List` }
}

function findEntity(projection, name) {
  return projection.entities.find(entity => entity.name === name)
}

test('absent entities, missing forms, wildcard forms and disabled actions fail closed', () => {
  const config = {
    entities: {
      NoForm: entityConfig('NoForm', { fields: undefined }),
      NoFields: entityConfig('NoFields', { fields: [] }),
      Wildcard: entityConfig('Wildcard', { fields: ['name', '__all__'] }),
      Disabled: entityConfig('Disabled', {
        fields: ['name', { property: 'secret', field_options: { disabled: true } }],
        disabled: ['new', 'edit'],
        detailDisabled: ['delete']
      }),
      ActionScoped: entityConfig('ActionScoped', {
        fields: [
          { property: 'mutable' },
          { property: 'immutable', hidden: 'update' },
          { property: 'never', hidden: true }
        ]
      })
    },
    routes: ['NoForm', 'NoFields', 'Wildcard', 'Disabled', 'ActionScoped'].map(route)
  }
  const projection = buildAdminConfigProjection(config)

  const noForm = findEntity(projection, 'NoForm')
  assert.equal(noForm.actions.create.configured, false)
  assert.equal(noForm.actions.update.configured, false)
  assert.equal(noForm.actions.delete.configured, true)
  assert.ok(noForm.actions.create.reasons.includes('missing_form'))

  for (const name of ['NoFields', 'Wildcard']) {
    const entity = findEntity(projection, name)
    assert.equal(entity.actions.create.configured, false)
    assert.equal(entity.actions.update.configured, false)
  }
  assert.ok(findEntity(projection, 'Wildcard').actions.create.reasons.includes('wildcard_form'))

  const disabled = findEntity(projection, 'Disabled')
  assert.equal(disabled.actions.create.configured, false)
  assert.equal(disabled.actions.update.configured, false)
  assert.equal(disabled.actions.delete.configured, false)
  assert.deepEqual(disabled.actions.create.fields.map(field => field.property), ['name'])
  assert.ok(projection.unsupported.some(item => item.entityName === 'Disabled' && item.reason === 'disabled_field'))

  const scoped = findEntity(projection, 'ActionScoped')
  assert.deepEqual(scoped.actions.create.fields.map(field => field.property), ['mutable', 'immutable'])
  assert.deepEqual(scoped.actions.update.fields.map(field => field.property), ['mutable'])
})

test('reserved keys, custom components and callbacks fail closed without prototype pollution', () => {
  const entities = {}
  Object.defineProperty(entities, '__proto__', {
    value: entityConfig('Polluted', { fields: ['name'] }),
    enumerable: true,
    configurable: true,
    writable: true
  })
  const unsafeField = { property: 'safe' }
  Object.defineProperty(unsafeField, '__proto__', {
    value: { polluted: true },
    enumerable: true,
    configurable: true,
    writable: true
  })
  entities.Safe = entityConfig('Safe', {
    fields: [
      'name',
      { property: '__proto__' },
      { property: 'constructor' },
      { property: 'custom', component: { name: 'CustomControl' } },
      { property: 'dynamic', hidden: () => true },
      { property: 'callbacks', rules: [{ validator: () => true }] },
      { property: 'unknownType', type: 'customWidget' },
      { property: 'disabledReadOnly', readOnly: true },
      unsafeField
    ]
  })

  const projection = buildAdminConfigProjection({ entities, routes: [route('Safe')] })
  assert.equal(findEntity(projection, 'Polluted'), undefined)
  assert.ok(projection.unsupported.some(item => item.reason === 'unsafe_entity_key'))

  const safe = findEntity(projection, 'Safe')
  assert.deepEqual(safe.actions.create.fields.map(field => field.property), ['name'])
  assert.deepEqual(safe.actions.update.fields.map(field => field.property), ['name'])
  for (const reason of ['reserved_property', 'custom_component', 'dynamic_hidden', 'callback_field', 'unsupported_type', 'disabled_field']) {
    assert.ok(projection.unsupported.some(item => item.entityName === 'Safe' && item.reason === reason), reason)
  }
  assert.equal(Object.prototype.polluted, undefined)
})

test('entity aliases use the configured entity name and validate route, plural and prefix paths', () => {
  const config = {
    entities: {
      CounterConfig: {
        entity: { name: 'Counter', plural: 'counters', prefix: '/api/v1/manage' },
        form: { fields: ['name'] },
        list: { disabled_actions: [] }
      },
      AliasNoRoute: entityConfig('AliasTarget', { fields: ['name'] }),
      BadPath: {
        entity: { name: 'BadPath', plural: 'bad-paths', prefix: '/api/v1/manage/../escape' },
        form: { fields: ['name'] },
        list: { disabled_actions: [] }
      }
    },
    routes: [
      { path: '/counters/list', name: 'CounterList' },
      { path: '/alias/list', name: 'AliasNoRouteList' },
      { path: '/bad/list', name: 'BadPathList' }
    ]
  }
  const projection = buildAdminConfigProjection(config, { parameterize: name => name.toLowerCase() + 's' })
  const counter = findEntity(projection, 'Counter')
  assert.equal(counter.key, 'CounterConfig')
  assert.equal(counter.path, '/api/v1/manage/counters')
  assert.equal(counter.route.name, 'CounterList')
  assert.equal(counter.actions.create.configured, true)

  assert.equal(findEntity(projection, 'AliasTarget').actions.create.configured, false)
  assert.ok(findEntity(projection, 'AliasTarget').actions.create.reasons.includes('missing_list_route'))
  assert.equal(findEntity(projection, 'BadPath').path, null)
  assert.equal(findEntity(projection, 'BadPath').actions.create.configured, false)
  assert.ok(findEntity(projection, 'BadPath').actions.create.reasons.includes('unsafe_or_missing_path'))

  const compiled = catalog([descriptor('Counter', ['create'], { create: { name: stringRule() } })])
  const filtered = intersectCapabilityCatalog(projection, compiled.capabilities)
  assert.deepEqual(filtered.map(capability => capability.entity), ['Counter'])
  assert.deepEqual(intersectCapabilityCatalog(projection, catalog([
    descriptor('CounterConfig', ['create'], { create: { name: stringRule() } })
  ]).capabilities), [])
})

test('semantic print capabilities require explicit nested config paths and never create pseudo entities', () => {
  const printingCases = ['order', 'perItem', 'filteredList', 'fullList', 'settlement']
  const storeSettings = {
    type: 'object',
    properties: {
      printing: {
        type: 'object',
        properties: Object.fromEntries(printingCases.map(property => [
          property,
          { type: 'object', properties: { enabled: { type: 'boolean' } } }
        ]))
      }
    }
  }
  const config = {
    entities: {
      Store: {
        entity: { name: 'Store', plural: 'stores' },
        form: {
          fields: [
            'name',
            { property: 'settings', type: 'json_schema', type_options: { schema: storeSettings } }
          ]
        },
        list: { disabled_actions: ['delete'] }
      }
    },
    routes: [route('Store')]
  }
  const validBinding = { StorePrinting: semanticCapabilityBindings.StorePrinting }
  const projection = buildAdminConfigProjection(config, { semanticBindings: validBinding })
  assert.deepEqual(projection.entities.map(entity => entity.name), ['Store'])
  assert.deepEqual(projection.semanticCapabilities.StorePrinting, {
    entity: 'Store',
    actions: ['update'],
    fields: {
      order: 'settings.printing.order',
      perItem: 'settings.printing.perItem',
      filteredList: 'settings.printing.filteredList',
      fullList: 'settings.printing.fullList',
      settlement: 'settings.printing.settlement'
    }
  })
  assert.equal(Object.isFrozen(semanticCapabilityBindings.StorePrinting.fields), true)
  const repeated = buildAdminConfigProjection(config, { semanticBindings: validBinding })
  assert.equal(repeated.revision, projection.revision)
  assert.notEqual(buildAdminConfigProjection(config).revision, projection.revision)
  const storeSettingsField = findEntity(projection, 'Store').actions.update.fields
    .find(field => field.property === 'settings')
  assert.ok(storeSettingsField.nestedPaths.includes('printing'))
  assert.ok(storeSettingsField.nestedPaths.includes('printing.order'))
  assert.ok(storeSettingsField.nestedPaths.includes('printing.order.enabled'))
  assert.ok(storeSettingsField.sourcePaths.includes('settings.printing.order.enabled'))

  const printingRule = {
    type: 'object',
    additionalProperties: false,
    properties: {
      enabled: { type: 'boolean' },
      deviceUuid: { type: 'uuid', pattern: UUID_PATTERN, nullable: true },
      templateUuid: { type: 'uuid', pattern: UUID_PATTERN, nullable: true },
      copies: { type: 'integer', minimum: 1, maximum: 100 }
    }
  }
  const printingCatalog = catalog([
    descriptor('StorePrinting', ['update'], { update: { order: printingRule } })
  ])
  const filtered = intersectCapabilityCatalog(projection, printingCatalog.capabilities)
  assert.deepEqual(filtered.map(capability => capability.entity), ['StorePrinting'])
  assert.equal(filtered[0].validateChanges({ order: { enabled: false } }, 'update'), true)

  for (const [reason, binding] of [
    ['unknown_semantic_target', { entity: 'Missing', actions: ['update'], fields: { order: 'settings.printing.order' } }],
    ['unmatched_semantic_path', { entity: 'Store', actions: ['update'], fields: { order: 'settings.missing.order' } }],
    ['unmatched_semantic_path', { entity: 'Store', actions: ['update'], fields: { order: 'settings.printing.__proto__' } }]
  ]) {
    const invalid = buildAdminConfigProjection(config, { semanticBindings: { StorePrinting: binding } })
    assert.deepEqual(invalid.semanticCapabilities, {})
    assert.ok(invalid.unsupported.some(item => item.entityName === 'StorePrinting' && item.reason === reason), reason)
    assert.deepEqual(intersectCapabilityCatalog(invalid, printingCatalog.capabilities), [])
  }

  const unbound = buildAdminConfigProjection(config)
  assert.deepEqual(unbound.semanticCapabilities, {})
  assert.deepEqual(intersectCapabilityCatalog(unbound, printingCatalog.capabilities), [])
})

test('runtime intersection filters actions and fields and keeps one instance set until a revision changes', () => {
  const config = {
    entities: {
      Tag: entityConfig('Tag', {
        fields: [
          'name',
          { property: 'slug', hidden: 'create' },
          { property: 'color', hidden: true }
        ]
      })
    },
    routes: [route('Tag')]
  }
  let projection = buildAdminConfigProjection(config)
  const compiled = catalog([
    descriptor('Tag', ['create', 'update', 'delete'], {
      create: {
        name: stringRule(100, { requiredOnCreate: true, nonEmpty: true }),
        slug: stringRule(),
        color: { type: 'color', nullable: true }
      },
      update: {
        name: stringRule(),
        slug: stringRule(),
        color: { type: 'color', nullable: true }
      }
    })
  ])
  let runtimeCapabilities = compiled.capabilities
  let runtimeRevision = REVISION
  const source = createAdminConfigCapabilitySource({
    getProjection: () => projection,
    getCapabilities: () => runtimeCapabilities,
    getRuntimeRevision: () => runtimeRevision
  })

  const first = source.getCapabilities()
  const second = source.getCapabilities()
  assert.equal(first, second)
  assert.equal(first[0], second[0])
  assert.deepEqual(first[0].actions, ['create', 'update', 'delete'])
  assert.deepEqual(Object.keys(first[0].actionFields.create), ['name'])
  assert.deepEqual(Object.keys(first[0].actionFields.update), ['name', 'slug'])
  assert.deepEqual(Object.keys(first[0].fields), ['name', 'slug'])
  assert.equal(first[0].validateChanges({ name: 'A' }, 'create'), true)
  assert.equal(first[0].validateChanges({ name: 'A', slug: 'a' }, 'create'), false)
  assert.equal(first[0].validateChanges({ color: '#123456' }, 'update'), false)
  assert.equal(first[0].validatePreview({
    entity: 'Tag',
    action: 'create',
    differences: [{ property: 'name', before: null, after: 'A' }]
  }), true)
  assert.equal(first[0].validatePreview({
    entity: 'Tag',
    action: 'create',
    differences: [{ property: 'slug', before: null, after: 'a' }]
  }), false)

  const revisionBefore = source.getRevision()
  projection = buildAdminConfigProjection({
    entities: {
      Tag: entityConfig('Tag', { fields: [{ property: 'name', hidden: 'update' }, 'slug'] })
    },
    routes: [route('Tag')]
  })
  const afterProjection = source.getCapabilities()
  assert.notEqual(afterProjection, first)
  assert.notEqual(source.getRevision(), revisionBefore)
  assert.deepEqual(Object.keys(afterProjection[0].actionFields.update), ['slug'])

  const projectionRevision = projection.revision
  runtimeRevision = 'b'.repeat(64)
  assert.equal(source.getRevision(), combineCapabilityRevision(runtimeRevision, projectionRevision))
  assert.notEqual(source.getCapabilities(), afterProjection)
})

test('agent delete allowlist narrows configured and backend delete capabilities', async () => {
  const { isAgentActionAllowed } = await import('../src/write-policy.js')
  assert.equal(isAgentActionAllowed('Tag', 'delete'), true)
  assert.equal(isAgentActionAllowed('Category', 'delete'), true)
  for (const entity of ['Content', 'User', 'Store', 'Product', 'Counter', 'Wallet']) {
    assert.equal(isAgentActionAllowed(entity, 'delete'), false, `${entity} deletion must remain unavailable to the assistant`)
  }
  assert.equal(isAgentActionAllowed('Content', 'update'), true)
})

test('required runtime fields excluded by config close the action; discovery alone is not executable', () => {
  const config = {
    entities: {
      Tag: entityConfig('Tag', { fields: ['name'] }),
      NewThing: entityConfig('NewThing', { fields: ['name'] })
    },
    routes: [route('Tag'), route('NewThing')]
  }
  const projection = buildAdminConfigProjection(config)
  assert.equal(findEntity(projection, 'NewThing').actions.create.configured, true)

  const compiled = catalog([
    descriptor('Tag', ['create', 'update'], {
      create: {
        name: stringRule(100, { requiredOnCreate: true }),
        slug: stringRule(100, { requiredOnCreate: true })
      },
      update: { name: stringRule() }
    })
  ])
  const filtered = intersectCapabilityCatalog(projection, compiled.capabilities)
  assert.deepEqual(filtered.map(capability => capability.entity), ['Tag'])
  assert.deepEqual(filtered[0].actions, ['update'])
  assert.deepEqual(Object.keys(filtered[0].actionFields.update), ['name'])

  const batchCompiled = catalog([
    descriptor('Tag', ['create'], {
      create: { name: stringRule(), slug: stringRule() }
    })
  ], [
    descriptor('Tag', ['create'], {
      create: { name: stringRule(), slug: stringRule() }
    })
  ])
  const filteredBatch = intersectCapabilityCatalog(projection, batchCompiled.batchCapabilities)
  assert.deepEqual(filteredBatch.map(capability => capability.entity), ['Tag'])
  assert.deepEqual(Object.keys(filteredBatch[0].actionFields.create), ['name'])
})

test('configured types, defaults, static options and relations are projected without copying host field tables', () => {
  const schema = {
    type: 'object',
    properties: {
      printing: {
        type: 'object',
        properties: { order: { type: 'object', properties: { enabled: { type: 'boolean' } } } }
      }
    }
  }
  const config = {
    entities: {
      Sample: entityConfig('Sample', {
        fields: [
          { property: 'enabled', type: 'boolean', default_value: true },
          { property: 'store', relation: { entity: 'Store', valueKey: 'uuid' } },
          {
            property: 'kind',
            type: 'select',
            type_options: { options: [{ value: 'a', label: 'Alpha' }] }
          },
          { property: 'settings', type: 'json_schema', type_options: { schema } },
          { property: 'password', type: 'password' },
          { property: 'unsafeDefault', default_value: { nested: true } }
        ]
      })
    },
    routes: [route('Sample')]
  }
  const projection = buildAdminConfigProjection(config)
  const fields = new Map(findEntity(projection, 'Sample').actions.create.fields.map(field => [field.property, field]))

  assert.equal(fields.get('enabled').type, 'boolean')
  assert.equal(fields.get('enabled').defaultValue, true)
  assert.deepEqual(fields.get('store').relation, {
    target: 'Store',
    valueKey: 'uuid',
    multiple: false
  })
  assert.equal(fields.get('kind').type, 'enum')
  assert.deepEqual(fields.get('kind').options, [{ value: 'a', label: 'Alpha' }])
  assert.ok(fields.get('settings').nestedPaths.includes('printing.order'))
  assert.ok(fields.get('settings').sourcePaths.includes('settings.printing.order'))
  assert.equal(fields.get('password').type, 'string')
  assert.equal(fields.has('unsafeDefault'), false)
  assert.ok(projection.unsupported.some(item => item.property === 'unsafeDefault' && item.reason === 'unsafe_default'))
})

test('nested JSON-schema paths restrict advertised rules, transport and changed canonical preview keys', async() => {
  const settingsSchema = {
    type: 'object',
    properties: {
      kept: {
        type: 'object',
        properties: { child: { type: 'string' } }
      }
    }
  }
  const config = {
    entities: {
      Widget: entityConfig('Widget', {
        fields: [
          'name',
          { property: 'settings', type: 'json_schema', type_options: { schema: settingsSchema } }
        ]
      })
    },
    routes: [route('Widget')]
  }
  assert.deepEqual(collectNestedPaths(settingsSchema, 'settings'), ['settings.kept', 'settings.kept.child'])
  const projection = buildAdminConfigProjection(config)
  const settingsField = findEntity(projection, 'Widget').actions.update.fields
    .find(field => field.property === 'settings')
  assert.deepEqual(settingsField.nestedPaths, ['kept', 'kept.child'])
  assert.deepEqual(settingsField.sourcePaths, ['settings.kept', 'settings.kept.child'])

  const rule = {
    type: 'object',
    additionalProperties: false,
    properties: {
      kept: {
        type: 'object',
        additionalProperties: false,
        properties: {
          child: { type: 'string', maxLength: 20 },
          hidden: { type: 'string', maxLength: 20 }
        }
      },
      removed: { type: 'string', maxLength: 20 }
    }
  }
  const compiled = catalog([
    descriptor('Widget', ['create', 'update'], { create: { name: stringRule(), settings: rule }, update: { name: stringRule(), settings: rule } })
  ])
  const filtered = intersectCapabilityCatalog(projection, compiled.capabilities)[0]
  const restricted = filtered.actionFields.update.settings
  assert.deepEqual(Object.keys(restricted.properties), ['kept'])
  assert.deepEqual(Object.keys(restricted.properties.kept.properties), ['child'])
  assert.equal(JSON.stringify(filtered.actionFields).includes('removed'), false)
  assert.equal(JSON.stringify(filtered.actionFields).includes('hidden'), false)
  assert.deepEqual(Object.keys(filtered.fields).sort(), ['name', 'settings'])

  assert.equal(filtered.validateChanges({ settings: { removed: 'x' } }, 'update'), false)
  assert.equal(filtered.validateChanges({ settings: { kept: { child: 'x' } } }, 'update'), true)
  assert.equal(filtered.validateChanges({ settings: { kept: { child: 'x', hidden: 'y' } } }, 'update'), false)

  const preview = (before, after, action = 'update') => ({
    entity: 'Widget',
    action,
    identifier: action === 'create' ? null : '1',
    differences: [{ property: 'settings', before, after }]
  })
  assert.equal(filtered.validatePreview(preview(
    { kept: { child: 'a' }, removed: 'r' },
    { kept: { child: 'b' }, removed: 'r' }
  )), true)
  assert.equal(filtered.validatePreview(preview(
    { kept: { child: 'a' }, removed: 'r' },
    { kept: { child: 'b' }, removed: 'changed' }
  )), false)
  assert.equal(filtered.validatePreview(preview(
    { kept: { child: 'a', hidden: 'h' } },
    { kept: { child: 'b', hidden: 'h2' } }
  )), false)
  assert.equal(filtered.validatePreview(preview(null, { kept: { child: 'a' }, removed: 'r' }, 'create')), false)

  const calls = []
  const host = createMutationHost({
    post: async() => {
      calls.push('post')
      return { data: { saved: false } }
    }
  }, '/operations', [filtered])
  await assert.rejects(host.prepareRecordChange({
    entity: 'Widget',
    action: 'update',
    identifier: '1',
    changes: { settings: { removed: 'x' } }
  }), /Changes contain unsupported/)
  assert.equal(calls.length, 0)
})

test('semantic print bindings restrict case children without breaking full canonical previews', () => {
  const printingCases = ['order', 'perItem', 'filteredList', 'fullList', 'settlement']
  const caseSchema = {
    type: 'object',
    properties: {
      enabled: { type: 'boolean' },
      templateUuid: { type: 'string' }
    }
  }
  const settingsSchema = {
    type: 'object',
    properties: {
      printing: {
        type: 'object',
        properties: Object.fromEntries(printingCases.map(property => [property, caseSchema]))
      }
    }
  }
  const config = {
    entities: {
      Store: {
        entity: { name: 'Store', plural: 'stores' },
        form: {
          fields: [
            'name',
            { property: 'settings', type: 'json_schema', type_options: { schema: settingsSchema } }
          ]
        },
        list: { disabled_actions: ['delete'] }
      }
    },
    routes: [route('Store')]
  }
  const projection = buildAdminConfigProjection(config, {
    semanticBindings: { StorePrinting: semanticCapabilityBindings.StorePrinting }
  })
  const uuid = 'a2c6b792-cab7-4fae-8a25-86f5d59e0210'
  const printingRule = {
    type: 'object',
    additionalProperties: false,
    properties: {
      enabled: { type: 'boolean' },
      deviceUuid: { type: 'uuid', pattern: UUID_PATTERN, nullable: true },
      templateUuid: { type: 'uuid', pattern: UUID_PATTERN, nullable: true },
      copies: { type: 'integer', minimum: 1, maximum: 100 }
    }
  }
  const compiled = catalog([
    descriptor('StorePrinting', ['update'], { update: { order: printingRule } })
  ])
  const filtered = intersectCapabilityCatalog(projection, compiled.capabilities)[0]
  const restricted = filtered.actionFields.update.order
  assert.deepEqual(Object.keys(restricted.properties), ['enabled', 'templateUuid'])
  assert.equal(JSON.stringify(filtered.actionFields).includes('copies'), false)
  assert.equal(JSON.stringify(filtered.actionFields).includes('deviceUuid'), false)

  assert.equal(filtered.validateChanges({ order: { copies: 2 } }, 'update'), false)
  assert.equal(filtered.validateChanges({ order: { deviceUuid: uuid } }, 'update'), false)
  assert.equal(filtered.validateChanges({ order: { enabled: false } }, 'update'), true)

  const before = { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 }
  const unchanged = { enabled: false, deviceUuid: null, templateUuid: null, copies: 1 }
  const preview = after => ({
    entity: 'StorePrinting',
    action: 'update',
    identifier: '12',
    differences: [{ property: 'order', before, after }]
  })
  assert.equal(filtered.validatePreview(preview(unchanged)), true)
  assert.equal(filtered.validatePreview(preview({ ...unchanged, copies: 2 })), false)
  assert.equal(filtered.validatePreview(preview({ ...unchanged, deviceUuid: uuid })), false)
  assert.equal(filtered.validatePreview(preview({ ...unchanged, templateUuid: uuid })), true)
})

test('scalar component bindings require the exact configured component and preserve explicit sibling fields', () => {
  const component = { name: 'ProductCategoryNameField', setup() {} }
  const config = {
    entities: {
      ProductCategory: {
        entity: { name: 'ProductCategory', plural: 'product-categories' },
        form: {
          fields: [
            { property: 'name', required: true, component },
            { property: 'slug', required: true }
          ]
        },
        list: { disabled_actions: [] }
      }
    },
    routes: [route('ProductCategory')]
  }
  const projection = buildAdminConfigProjection(config, { componentBindings: scalarComponentBindings })
  const nameField = findEntity(projection, 'ProductCategory').actions.create.fields
    .find(field => field.property === 'name')
  assert.equal(nameField.type, 'string')
  assert.equal(nameField.componentName, 'ProductCategoryNameField')
  assert.equal(nameField.required, true)
  assert.equal(Object.hasOwn(nameField, 'maxLength'), false)

  const unbound = buildAdminConfigProjection(config)
  assert.equal(findEntity(unbound, 'ProductCategory').actions.create.fields.some(field => field.property === 'name'), false)
  assert.ok(unbound.unsupported.some(item => item.property === 'name' && item.reason === 'custom_component'))

  const renamed = buildAdminConfigProjection({
    ...config,
    entities: {
      ProductCategory: {
        ...config.entities.ProductCategory,
        form: { fields: [{ property: 'name', required: true, component: { name: 'RenamedField' } }, { property: 'slug' }] }
      }
    }
  }, { componentBindings: scalarComponentBindings })
  assert.equal(findEntity(renamed, 'ProductCategory').actions.create.fields.some(field => field.property === 'name'), false)

  const compiled = catalog([
    descriptor('ProductCategory', ['create'], {
      create: {
        name: stringRule(255, { requiredOnCreate: true }),
        slug: stringRule(255, { requiredOnCreate: true })
      }
    })
  ])
  const filtered = intersectCapabilityCatalog(projection, compiled.capabilities)[0]
  assert.deepEqual(Object.keys(filtered.actionFields.create), ['name', 'slug'])
  assert.equal(filtered.validateChanges({ name: 'Food' }, 'create'), false)
  assert.equal(filtered.validateChanges({ name: 'Food', slug: 'food' }, 'create'), true)

  const actualConfig = readFileSync(new URL('../../../core/crud-admin/src/configs/collections/trade/ProductCategory.js', import.meta.url), 'utf8')
  const actualComponent = readFileSync(new URL('../../../core/crud-admin/src/configs/collections/trade/ProductCategoryNameField.jsx', import.meta.url), 'utf8')
  assert.match(actualConfig, /property: 'name'[\s\S]*?component: ProductCategoryNameField/)
  assert.match(actualComponent, /name: 'ProductCategoryNameField'/)
  assert.equal(scalarComponentBindings.ProductCategory.name.componentName, 'ProductCategoryNameField')
})

test('readonly option forms are removed from model metadata and rejected before transport', async() => {
  const config = {
    entities: {
      Widget: entityConfig('Widget', {
        fields: [
          'name',
          { property: 'readonlyLower', type_options: { readonly: true } },
          { property: 'readonlyUpper', type_options: { readOnly: true } },
          { property: 'fieldReadonlyLower', field_options: { readonly: true } },
          { property: 'fieldReadonlyUpper', field_options: { readOnly: true } },
          { property: 'disabledOption', type_options: { disabled: true } }
        ]
      })
    },
    routes: [route('Widget')]
  }
  const projection = buildAdminConfigProjection(config)
  assert.deepEqual(findEntity(projection, 'Widget').actions.create.fields.map(field => field.property), ['name'])
  for (const property of ['readonlyLower', 'readonlyUpper', 'fieldReadonlyLower', 'fieldReadonlyUpper', 'disabledOption']) {
    assert.ok(projection.unsupported.some(item => item.property === property && item.reason === 'disabled_field'))
  }

  const fields = {
    name: stringRule(),
    readonlyLower: stringRule(),
    readonlyUpper: stringRule(),
    fieldReadonlyLower: stringRule(),
    fieldReadonlyUpper: stringRule(),
    disabledOption: stringRule()
  }
  const compiled = catalog([
    descriptor('Widget', ['update'], { update: fields }, fields)
  ])
  const filtered = intersectCapabilityCatalog(projection, compiled.capabilities)[0]
  assert.deepEqual(Object.keys(filtered.actionFields.update), ['name'])
  assert.equal(JSON.stringify(filtered.actionFields).includes('readonly'), false)
  assert.equal(filtered.validateChanges({ readonlyLower: 'x' }, 'update'), false)

  const calls = []
  const host = createMutationHost({
    post: async() => {
      calls.push('post')
      return { data: { saved: false } }
    }
  }, '/operations', [filtered])
  await assert.rejects(host.prepareRecordChange({
    entity: 'Widget',
    action: 'update',
    identifier: '1',
    changes: { readonlyLower: 'x' }
  }), /Changes contain unsupported/)
  assert.equal(calls.length, 0)
})

test('route availability narrows runtime actions without removing configured entities', () => {
  const config = {
    entities: {
      Alpha: entityConfig('Alpha', { fields: ['name'] }),
      Beta: entityConfig('Beta', { fields: ['name'] })
    },
    routes: [route('Alpha'), route('Beta')]
  }
  const staticProjection = buildAdminConfigProjection(config)
  assert.equal(findEntity(staticProjection, 'Alpha').actions.create.configured, true)
  assert.equal(findEntity(staticProjection, 'Beta').actions.create.configured, true)

  const runtimeProjection = buildAdminConfigProjection(config, { routeAvailability: new Set(['AlphaList']) })
  assert.equal(findEntity(runtimeProjection, 'Alpha').actions.create.configured, true)
  assert.equal(findEntity(runtimeProjection, 'Beta').actions.create.configured, false)
  assert.ok(findEntity(runtimeProjection, 'Beta'))

  const aliasProjection = buildAdminConfigProjection(config, { availableRouteNames: ['BetaList'] })
  assert.equal(findEntity(aliasProjection, 'Alpha').actions.create.configured, false)
  assert.equal(findEntity(aliasProjection, 'Beta').actions.create.configured, true)
})

test('capability identity includes runtime epochs so a same-hash failure recovery rejects pending work', async() => {
  const config = {
    entities: { Tag: entityConfig('Tag', { fields: ['name', 'slug', 'color'] }) },
    routes: [route('Tag')]
  }
  const projection = buildAdminConfigProjection(config)
  const waitFor = async predicate => {
    for (let index = 0; index < 100 && !predicate(); index += 1) {
      await new Promise(resolve => setTimeout(resolve, 0))
    }
    assert.equal(predicate(), true)
  }

  const assertIdentity = combineCapabilityRevision(REVISION, projection.revision, 1, 0)
  assert.notEqual(assertIdentity, combineCapabilityRevision(REVISION, projection.revision, 2, 0))
  assert.equal(combineCapabilityRevision(REVISION, projection.revision), `${REVISION}:${projection.revision}`)

  let mode = 'ok'
  let pendingPost = null
  const request = {
    async get(path) {
      assert.ok(path.endsWith('/capabilities'))
      return {
        data: mode === 'ok'
          ? catalogResponse
          : { schemaVersion: 2, revision: catalogResponse.revision, capabilities: [], batchCapabilities: [] }
      }
    },
    post() {
      return new Promise(resolve => { pendingPost = resolve })
    }
  }
  const runtime = createCapabilityCatalogRuntime({ request, compile: compileMutationCapabilities })
  await runtime.ensure()
  const source = createAdminConfigCapabilitySource({
    getProjection: () => projection,
    getCapabilities: () => runtime.current?.capabilities ?? [],
    getRuntimeRevision: () => runtime.revision,
    getRuntimeEpoch: () => runtime.epoch,
    getProjectionEpoch: () => 0
  })
  const host = createMutationHost(request, '/operations', source)
  const initialEpoch = runtime.epoch
  const initialIdentity = source.getRevision()
  assert.equal(source.getIdentity(), initialIdentity)
  await runtime.refresh()
  assert.equal(source.getRevision(), initialIdentity)
  const pending = host.prepareRecordChange({
    entity: 'Tag',
    action: 'create',
    changes: { name: 'Vegan', slug: 'vegan' }
  })
  await waitFor(() => pendingPost !== null)
  mode = 'fail'
  await assert.rejects(runtime.refresh(), error => error.code === 'unsupported_schema_version')
  mode = 'ok'
  await runtime.refresh()
  assert.equal(runtime.revision, catalogResponse.revision)
  assert.notEqual(runtime.epoch, initialEpoch)
  assert.notEqual(source.getRevision(), initialIdentity)
  pendingPost({ data: { saved: false } })
  await assert.rejects(pending, error => error.code === 'capabilities_changed')
  runtime.dispose()

  let restoreMode = 'ok'
  let pendingPreview = null
  const restoreRequest = {
    async get(path) {
      if (path.endsWith('/capabilities')) {
        return {
          data: restoreMode === 'ok'
            ? catalogResponse
            : { schemaVersion: 2, revision: catalogResponse.revision, capabilities: [], batchCapabilities: [] }
        }
      }
      assert.ok(path.endsWith('/preview'))
      return new Promise(resolve => { pendingPreview = resolve })
    },
    async post() {
      throw new Error('Restore must not post.')
    }
  }
  const restoreRuntime = createCapabilityCatalogRuntime({ request: restoreRequest, compile: compileMutationCapabilities })
  await restoreRuntime.ensure()
  const restoreSource = createAdminConfigCapabilitySource({
    getProjection: () => projection,
    getCapabilities: () => restoreRuntime.current?.capabilities ?? [],
    getRuntimeRevision: () => restoreRuntime.revision,
    getRuntimeEpoch: () => restoreRuntime.epoch,
    getProjectionEpoch: () => 0
  })
  const restoreHost = createMutationHost(restoreRequest, '/operations', restoreSource)
  const restore = restoreHost.restoreRecordChange('a'.repeat(64))
  await waitFor(() => pendingPreview !== null)
  restoreMode = 'fail'
  await assert.rejects(restoreRuntime.refresh(), error => error.code === 'unsupported_schema_version')
  restoreMode = 'ok'
  await restoreRuntime.refresh()
  pendingPreview({
    data: {
      entity: 'Tag',
      action: 'update',
      identifier: '12',
      title: 'Vegan',
      differences: [{ property: 'color', before: null, after: '#123456' }]
    }
  })
  await assert.rejects(restore, error => error.code === 'capabilities_changed')
  restoreRuntime.dispose()
})

test('restricted nested containers cannot be cleared over hidden descendants', () => {
  const contactSchema = {
    type: 'object',
    properties: { managerName: { type: 'string' } }
  }
  const config = {
    entities: {
      Widget: entityConfig('Widget', {
        fields: [
          { property: 'contact', type: 'json_schema', type_options: { schema: contactSchema } },
          'deviceUuid'
        ]
      })
    },
    routes: [route('Widget')]
  }
  assert.deepEqual(collectNestedPaths({
    type: 'object',
    properties: { empty: { type: 'object', properties: {} } }
  }), [])

  const projection = buildAdminConfigProjection(config)
  const rule = {
    type: 'object',
    nullable: true,
    additionalProperties: false,
    properties: {
      managerName: { type: 'string', maxLength: 80 },
      phone: { type: 'string', maxLength: 40 }
    }
  }
  const deviceRule = { type: 'uuid', pattern: UUID_PATTERN, nullable: true }
  const compiled = catalog([
    descriptor('Widget', ['update'], { update: { contact: rule, deviceUuid: deviceRule } })
  ])
  const filtered = intersectCapabilityCatalog(projection, compiled.capabilities)[0]
  assert.equal(filtered.actionFields.update.contact.nullable, false)
  assert.deepEqual(Object.keys(filtered.actionFields.update.contact.properties), ['managerName'])
  assert.equal(filtered.validateChanges({ contact: { managerName: 'Manager' } }, 'update'), true)
  assert.equal(filtered.validateChanges({ contact: null }, 'update'), false)
  assert.equal(filtered.validateChanges({ deviceUuid: null }, 'update'), true)

  const preview = (before, after) => ({
    entity: 'Widget',
    action: 'update',
    identifier: '1',
    differences: [
      { property: 'contact', before, after },
      { property: 'deviceUuid', before: null, after: null }
    ]
  })
  assert.equal(filtered.validatePreview(preview({ managerName: 'A', phone: 'secret' }, null)), false)
  assert.equal(filtered.validatePreview(preview({ managerName: 'A', phone: 'secret' }, { managerName: 'B', phone: 'secret' })), true)
  assert.equal(filtered.validatePreview(preview({ managerName: 'A', phone: 'secret' }, { managerName: 'B' })), false)
})

test('nested schema form overrides use the injected resolver and stay action-specific', () => {
  const schema = {
    type: 'object',
    properties: {
      printing: {
        type: 'object',
        properties: {
          order: {
            type: 'object',
            properties: {
              enabled: { type: 'boolean' },
              copies: { type: 'integer' },
              deviceUuid: { type: 'string' },
              templateUuid: { type: 'string' }
            }
          }
        }
      }
    }
  }
  const fields = [{
    property: 'printing',
    type_options: {
      fields: [{
        property: 'order',
        type_options: {
          fields: [
            { property: 'enabled', field_options: { disabled: true } },
            { property: 'copies', hidden: 'update' }
          ]
        }
      }]
    }
  }]
  const resolver = (currentSchema, configuredFields = []) => {
    const generated = Object.entries(currentSchema.properties || {}).map(([property, propertySchema]) => ({
      property,
      type: propertySchema.type === 'object' ? 'json_schema' : propertySchema.type,
      ...(propertySchema.type === 'object' ? { type_options: { schema: propertySchema } } : {})
    }))
    const overrides = new Map((Array.isArray(configuredFields) ? configuredFields : []).map(field => [field.property, field]))
    return {
      fields: generated.map(field => {
        const override = overrides.get(field.property)
        if (!override) return field
        return {
          ...field,
          ...override,
          type_options: { ...field.type_options, ...override.type_options }
        }
      })
    }
  }
  const config = {
    entities: {
      Widget: entityConfig('Widget', {
        fields: [{ property: 'settings', type: 'json_schema', type_options: { schema, fields } }]
      })
    },
    routes: [route('Widget')]
  }
  const withoutResolver = buildAdminConfigProjection(config)
  assert.equal(findEntity(withoutResolver, 'Widget').actions.update.fields.some(field => field.property === 'settings'), false)
  assert.ok(withoutResolver.unsupported.some(item => item.property === 'settings' && item.reason === 'unsupported_schema_form'))

  let calls = 0
  const projection = buildAdminConfigProjection(config, {
    schemaFormResolver: (currentSchema, currentFields) => {
      calls += 1
      return resolver(currentSchema, currentFields)
    }
  })
  const createSettings = findEntity(projection, 'Widget').actions.create.fields
    .find(field => field.property === 'settings')
  const updateSettings = findEntity(projection, 'Widget').actions.update.fields
    .find(field => field.property === 'settings')
  assert.ok(calls > 0)
  assert.deepEqual(createSettings.nestedPaths, [
    'printing', 'printing.order', 'printing.order.copies',
    'printing.order.deviceUuid', 'printing.order.templateUuid'
  ])
  assert.deepEqual(updateSettings.nestedPaths, [
    'printing', 'printing.order', 'printing.order.deviceUuid', 'printing.order.templateUuid'
  ])
  assert.equal(createSettings.nestedPaths.includes('printing.order.enabled'), false)
  assert.ok(createSettings.sourcePaths.includes('settings.printing.order.copies'))
  assert.equal(updateSettings.sourcePaths.includes('settings.printing.order.copies'), false)

  const invalidOverride = buildAdminConfigProjection({
    ...config,
    entities: {
      Widget: entityConfig('Widget', {
        fields: [{
          property: 'settings',
          type: 'json_schema',
          type_options: {
            schema,
            fields: [{
              property: 'printing',
              type_options: {
                fields: [{
                  property: 'order',
                  type_options: {
                    fields: [{ property: 'templateUuid', type_options: { readonly: 'true' } }]
                  }
                }]
              }
            }]
          }
        }]
      })
    }
  }, { schemaFormResolver: resolver })
  assert.equal(findEntity(invalidOverride, 'Widget').actions.update.fields.some(field => field.property === 'settings'), false)
  assert.ok(invalidOverride.unsupported.some(item => item.property === 'settings' && item.reason === 'unsupported_schema_form'))
})

test('non-boolean readonly/disabled policy values fail closed instead of being treated as false', () => {
  const config = {
    entities: {
      Widget: entityConfig('Widget', {
        fields: [
          'name',
          { property: 'stringTrue', type_options: { readonly: 'true' } },
          { property: 'stringFalse', type_options: { readOnly: 'false' } },
          { property: 'numberOne', type_options: { disabled: 1 } },
          { property: 'fieldString', field_options: { readonly: 'readonly' } },
          { property: 'fieldZero', field_options: { readOnly: 0 } }
        ]
      })
    },
    routes: [route('Widget')]
  }
  const projection = buildAdminConfigProjection(config)
  assert.deepEqual(findEntity(projection, 'Widget').actions.create.fields.map(field => field.property), ['name'])
  for (const property of ['stringTrue', 'stringFalse', 'numberOne', 'fieldString', 'fieldZero']) {
    assert.ok(projection.unsupported.some(item => item.property === property && item.reason === 'invalid_policy_value'))
  }
})

test('schema-known fields with zero writable descendants are omitted per action while raw JSON remains', () => {
  const metadataSchema = {
    type: 'object',
    properties: { featured: { type: 'boolean' }, layout: { type: 'string' } }
  }
  const createOnlySchema = {
    type: 'object',
    properties: { value: { type: 'string' } }
  }
  const emptySchema = { type: 'object', properties: {} }
  const resolver = (schema, configuredFields = []) => {
    if (schema === metadataSchema) {
      return { fields: [
        { property: 'featured', field_options: { disabled: true } },
        { property: 'layout', type_options: { readonly: true } }
      ] }
    }
    if (schema === createOnlySchema) {
      return { fields: [{ property: 'value', hidden: 'update' }] }
    }
    if (schema === emptySchema) return { fields: [] }
    return { fields: [] }
  }
  const config = {
    entities: {
      Content: entityConfig('Content', {
        fields: [
          { property: 'metadata', type: 'json_schema', type_options: { schema: metadataSchema } },
          { property: 'createOnly', type: 'json_schema', type_options: { schema: createOnlySchema } },
          { property: 'empty', type: 'json_schema', type_options: { schema: emptySchema } },
          { property: 'rawJson', type: 'json' }
        ]
      })
    },
    routes: [route('Content')]
  }
  const projection = buildAdminConfigProjection(config, { schemaFormResolver: resolver })
  const createFields = findEntity(projection, 'Content').actions.create.fields.map(field => field.property)
  const updateFields = findEntity(projection, 'Content').actions.update.fields.map(field => field.property)
  assert.deepEqual(createFields, ['createOnly', 'rawJson'])
  assert.deepEqual(updateFields, ['rawJson'])
  assert.equal(JSON.stringify(findEntity(projection, 'Content').actions).includes('"nestedPaths":[]'), false)
  assert.ok(findEntity(projection, 'Content').actions.create.fields.find(field => field.property === 'createOnly').nestedPaths.includes('value'))
  const raw = findEntity(projection, 'Content').actions.update.fields.find(field => field.property === 'rawJson')
  assert.equal(Object.hasOwn(raw, 'nestedPaths'), false)
})

test('commit preflight re-reads configuration after the awaited catalog refresh and never posts on change', async() => {
  let generation = 0
  let revision = 'a'
  let projections = 0
  let refreshes = 0
  let posted = false
  const getIdentity = () => `${revision}:${generation}`

  await assert.rejects((async() => {
    await ensureFreshCommitCapability({
      getIdentity,
      refreshProjection: () => { projections += 1 },
      refreshCatalog: async() => {
        refreshes += 1
        await Promise.resolve()
        generation += 1
      }
    })
    posted = true
  })(), error => error.code === 'proposal_conflict')
  assert.equal(posted, false)
  assert.equal(projections, 2)
  assert.equal(refreshes, 1)

  await assert.rejects(ensureFreshCommitCapability({
    getIdentity,
    refreshProjection: () => {},
    refreshCatalog: async() => { throw new Error('catalog unavailable') }
  }), error => error.code === 'proposal_conflict')

  let refreshed = 0
  await ensureFreshCommitCapability({
    getIdentity: () => 'stable',
    refreshProjection: () => {},
    refreshCatalog: async() => {},
    onRefreshed: () => { refreshed += 1 }
  })
  assert.equal(refreshed, 1)
})
