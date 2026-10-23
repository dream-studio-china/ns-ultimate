import assert from 'node:assert/strict'
import fsp from 'node:fs/promises'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import test from 'node:test'

import { buildAdminConfigProjection } from '../../apps/admin-agent/src/admin-config-policy.js'
import {
  AGENT_MANIFEST_SCHEMA_VERSION,
  AGENT_MANIFEST_SOURCE,
  buildAgentManifest,
  createAgentConfigServer,
  createAgentManifestPublication,
  generateAgentManifest,
  HEADLESS_VITE_CACHE_DIR,
  loadEffectiveAdminConfig,
  removeAgentManifest,
  serializeAgentManifest,
  validateAgentManifest,
  writeAgentManifest
} from './export-agent-manifest.mjs'
import { agentManifestPlugin } from './manifest-plugin.mjs'

async function temporaryDirectory() {
  return fsp.mkdtemp(path.join(os.tmpdir(), 'ns-ultimate-manifest-'))
}

async function withTemporaryDirectory(callback) {
  const directory = await temporaryDirectory()
  try {
    return await callback(directory)
  } finally {
    await fsp.rm(directory, { recursive: true, force: true })
  }
}

function entityByName(manifest, name) {
  return manifest.entities.find(entity => entity.name === name)
}

function fieldNames(action) {
  return action.fields.map(field => field.property)
}

function assertMinimalShape(manifest) {
  assert.deepEqual(Object.keys(manifest), ['schemaVersion', 'revision', 'source', 'entities', 'semanticCapabilities'])
  assert.equal(manifest.schemaVersion, AGENT_MANIFEST_SCHEMA_VERSION)
  assert.equal(manifest.source, AGENT_MANIFEST_SOURCE)
  assert.match(manifest.revision, /^[0-9a-f]{64}$/)
  assert.ok(manifest.entities.length > 0)
  assert.ok(Array.isArray(manifest.semanticCapabilities))

  for (const entity of manifest.entities) {
    assert.deepEqual(Object.keys(entity), ['key', 'name', 'actions'])
    assert.deepEqual(Object.keys(entity.actions), ['create', 'update', 'delete'])
    for (const actionName of ['create', 'update', 'delete']) {
      const action = entity.actions[actionName]
      assert.deepEqual(Object.keys(action), ['configured', 'fields'])
      assert.equal(typeof action.configured, 'boolean')
      for (const field of action.fields) {
        assert.ok(Object.keys(field).every(key => ['property', 'nestedPaths'].includes(key)))
        assert.match(field.property, /^[A-Za-z][A-Za-z0-9_]{0,63}$/)
        if (field.nestedPaths !== undefined) {
          assert.ok(Array.isArray(field.nestedPaths))
          for (const nestedPath of field.nestedPaths) {
            assert.match(nestedPath, /^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){0,7}$/)
          }
        }
      }
    }
  }

  for (const capability of manifest.semanticCapabilities) {
    assert.deepEqual(Object.keys(capability), ['name', 'entity', 'actions', 'fields'])
    assert.ok(capability.actions.length > 0)
    assert.ok(capability.fields.length > 0)
    for (const field of capability.fields) {
      assert.ok(Object.keys(field).every(key => ['property', 'path', 'nestedPaths'].includes(key)))
      assert.match(field.path, /^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*){0,7}$/)
    }
  }
}

function fakeFileSystem({ rename, open } = {}) {
  return {
    mkdir: fsp.mkdir,
    open: open || fsp.open,
    rename: rename || fsp.rename,
    rm: fsp.rm
  }
}

test('loads the real composed config without listening or network access', async() => {
  const originalListen = net.Server.prototype.listen
  let listened = false
  net.Server.prototype.listen = function listen() {
    listened = true
    throw new Error('The headless config loader must not listen.')
  }

  try {
    const loaded = await loadEffectiveAdminConfig({ logLevel: 'silent' })
    assert.equal(listened, false)
    assert.equal(loaded.defaultPrefix, '/api/v1/manage')
    assert.equal(loaded.parameterize('PrinterDevice'), 'printer-devices')
    assert.ok(Array.isArray(loaded.config.routes))
    assert.ok(loaded.config.entities.Tag)
    assert.ok(loaded.config.entities.Content)
    assert.ok(loaded.config.entities.Counter)
    assert.ok(loaded.semanticBindings.StorePrinting)
    assert.ok(loaded.componentBindings.ProductCategory)
    assert.equal(typeof loaded.schemaFormResolver, 'function')
    const resolved = loaded.schemaFormResolver({
      type: 'object',
      properties: {
        nested: {
          type: 'object',
          properties: { child: { type: 'string' } }
        }
      }
    }, [{
      property: 'nested',
      type_options: {
        fields: [{ property: 'child', field_options: { readonly: true } }]
      }
    }])
    assert.equal(resolved.fields[0].type_options.fields[0].field_options.readonly, true)
    const lingering = process._getActiveHandles().filter(handle => (
      handle?.constructor?.name === 'FSWatcher' || handle?.constructor?.name === 'Server'
    ))
    assert.deepEqual(lingering, [])
  } finally {
    net.Server.prototype.listen = originalListen
  }
})

test('creates a closed, non-listening Vite server without the manifest plugin', async() => {
  const originalListen = net.Server.prototype.listen
  net.Server.prototype.listen = function listen() {
    throw new Error('The headless config loader must not listen.')
  }

  let server
  try {
    server = await createAgentConfigServer({ logLevel: 'silent' })
    assert.equal(server.httpServer, null)
    assert.equal(server.config.plugins.some(plugin => plugin.name === 'ns-ultimate-agent-manifest'), false)
    assert.equal(server.config.cacheDir, HEADLESS_VITE_CACHE_DIR)
    assert.equal(server.config.cacheDir.startsWith(`${server.config.root}${path.sep}`), false)
    assert.notEqual(server.config.cacheDir, path.join(server.config.root, 'node_modules/.vite'))
    assert.equal(server.config.optimizeDeps.noDiscovery, true)
    assert.deepEqual(server.config.optimizeDeps.include, [])
    assert.equal(server.config.ssr.optimizeDeps.noDiscovery, true)
    assert.deepEqual(server.config.ssr.optimizeDeps.include, [])
  } finally {
    if (server) await server.close()
    net.Server.prototype.listen = originalListen
  }
})

test('exports the same bytes and revision on repeated runs', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    const first = await generateAgentManifest({ outFile, logLevel: 'silent' })
    const firstBytes = await fsp.readFile(outFile, 'utf8')
    const second = await generateAgentManifest({ outFile, logLevel: 'silent' })
    const secondBytes = await fsp.readFile(outFile, 'utf8')

    assert.equal(first.manifest.revision, second.manifest.revision)
    assert.equal(firstBytes, secondBytes)
    assert.deepEqual(JSON.parse(firstBytes), first.manifest)
    assertMinimalShape(first.manifest)
    assert.doesNotThrow(() => validateAgentManifest(first.manifest))
    assert.equal(serializeAgentManifest(first.manifest), firstBytes)
    assert.equal((await fsp.stat(outFile)).mode & 0o777, 0o600)
    assert.equal((await fsp.stat(directory)).mode & 0o777, 0o700)
  })
})

test('exports only effective entity/action/field metadata for known configuration', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    const { manifest } = await generateAgentManifest({ outFile, logLevel: 'silent' })
    assertMinimalShape(manifest)

    const content = entityByName(manifest, 'Content')
    assert.ok(content)
    assert.deepEqual(fieldNames(content.actions.create), ['title', 'body', 'category', 'tags'])
    assert.deepEqual(fieldNames(content.actions.update), ['title', 'body', 'category', 'tags'])
    assert.equal(content.actions.delete.configured, true)

    const tag = entityByName(manifest, 'Tag')
    assert.ok(tag)
    assert.deepEqual(fieldNames(tag.actions.create), ['name', 'slug', 'color'])

    const counter = entityByName(manifest, 'Counter')
    assert.ok(counter)
    assert.deepEqual(fieldNames(counter.actions.create), [
      'storeUuid', 'code', 'name', 'capacity', 'sort', 'metadata', 'enabled'
    ])

    assert.deepEqual(
      manifest.semanticCapabilities.map(capability => capability.name),
      ['ProductPrinting', 'StorePrinting']
    )
    const storePrinting = manifest.semanticCapabilities.find(capability => capability.name === 'StorePrinting')
    assert.equal(storePrinting.entity, 'Store')
    assert.deepEqual(storePrinting.actions, ['update'])
    const storeOrder = storePrinting.fields.find(field => field.property === 'order')
    assert.equal(storeOrder.path, 'settings.printing.order')
    assert.deepEqual(storeOrder.nestedPaths, [
      'settings.printing.order.enabled',
      'settings.printing.order.deviceUuid',
      'settings.printing.order.templateUuid',
      'settings.printing.order.copies'
    ])
    const productPrinting = manifest.semanticCapabilities.find(capability => capability.name === 'ProductPrinting')
    assert.deepEqual(productPrinting.fields, [{
      property: 'kitchenPrinterEnable',
      path: 'settings.kitchenPrinterEnable'
    }])

    const storeSettings = entityByName(manifest, 'Store').actions.update.fields
      .find(field => field.property === 'settings')
    assert.ok(storeSettings.nestedPaths.includes('settings.printing.order.copies'))
    assert.ok(storeSettings.nestedPaths.includes('settings.printing.order.deviceUuid'))
    const productSettings = entityByName(manifest, 'Product').actions.update.fields
      .find(field => field.property === 'settings')
    assert.ok(productSettings.nestedPaths.includes('settings.kitchenPrinterEnable'))

    const productCategory = entityByName(manifest, 'ProductCategory')
    assert.ok(productCategory)
    assert.ok(fieldNames(productCategory.actions.create).includes('name'))
    assert.ok(fieldNames(productCategory.actions.create).includes('slug'))

    const names = new Set(manifest.entities.map(entity => entity.name))
    for (const componentKey of ['name', 'props', 'setup', '__hmrId']) {
      assert.equal(names.has(componentKey), false)
      assert.equal(manifest.entities.some(entity => entity.key === componentKey), false)
    }

    const text = await fsp.readFile(outFile, 'utf8')
    for (const forbidden of ['"routes"', '"defaultValue"', '"schema"', '"component"', '"$fn"', 'http://', 'https://']) {
      assert.equal(text.includes(forbidden), false, `Manifest contains forbidden payload: ${forbidden}`)
    }
  })
})

test('passes the raw core schemaFormResolver to the shared projector', () => {
  const schemaFormResolver = () => null
  let received
  buildAgentManifest({}, {
    schemaFormResolver,
    projector: (_config, options) => {
      received = options.schemaFormResolver
      return {
        entities: [{
          key: 'Example',
          name: 'Example',
          actions: {
            create: { configured: true, fields: [{ property: 'name' }] },
            update: { configured: true, fields: [{ property: 'name' }] },
            delete: { configured: false, fields: [] }
          }
        }]
      }
    }
  })
  assert.equal(received, schemaFormResolver)
})

test('builds deterministically from an injected projector and changes revision with semantics', () => {
  const projector = () => ({
    entities: [{
      key: 'Example',
      name: 'Example',
      actions: {
        create: { configured: true, fields: [{ property: 'name' }] },
        update: { configured: true, fields: [{ property: 'name' }] },
        delete: { configured: false, fields: [] }
      }
    }]
  })
  const first = buildAgentManifest({}, { projector })
  const second = buildAgentManifest({}, { projector })
  assert.deepEqual(first, second)
  assertMinimalShape(first)

  const changed = buildAgentManifest({}, {
    projector: () => ({
      entities: [{
        key: 'Example',
        name: 'Example',
        actions: {
          create: { configured: true, fields: [{ property: 'name' }, { property: 'code' }] },
          update: { configured: true, fields: [{ property: 'name' }, { property: 'code' }] },
          delete: { configured: false, fields: [] }
        }
      }]
    })
  })
  assert.notEqual(first.revision, changed.revision)
})

test('semantic fields carry configured leaf coverage and narrow with the source schema', () => {
  const projection = leaves => () => ({
    entities: [{
      key: 'Store',
      name: 'Store',
      actions: {
        create: { configured: false, fields: [] },
        update: {
          configured: true,
          fields: [{
            property: 'settings',
            sourcePaths: ['settings.printing.order', ...leaves.map(leaf => `settings.printing.order.${leaf}`)]
          }]
        },
        delete: { configured: false, fields: [] }
      }
    }],
    semanticCapabilities: {
      StorePrinting: {
        entity: 'Store',
        actions: ['update'],
        fields: { order: 'settings.printing.order' }
      }
    }
  })

  const full = buildAgentManifest({}, { projector: projection(['enabled', 'deviceUuid', 'copies']) })
  assert.deepEqual(full.semanticCapabilities[0].fields[0].nestedPaths, [
    'settings.printing.order.enabled',
    'settings.printing.order.deviceUuid',
    'settings.printing.order.copies'
  ])
  const narrowed = buildAgentManifest({}, { projector: projection(['enabled', 'copies']) })
  assert.deepEqual(narrowed.semanticCapabilities[0].fields[0].nestedPaths, [
    'settings.printing.order.enabled',
    'settings.printing.order.copies'
  ])
  assert.notEqual(full.revision, narrowed.revision)
})

test('semantic bindings fail closed when their configured source path disappears', () => {
  const projector = () => ({
    entities: [{
      key: 'Store',
      name: 'Store',
      actions: {
        create: { configured: false, fields: [] },
        update: {
          configured: true,
          fields: [{ property: 'settings', sourcePaths: ['settings.printing.order.enabled'] }]
        },
        delete: { configured: false, fields: [] }
      }
    }],
    semanticCapabilities: {
      StorePrinting: {
        entity: 'Store',
        actions: ['update'],
        fields: { order: 'settings.printing.order' }
      }
    }
  })
  assert.throws(
    () => buildAgentManifest({}, { projector }),
    /not configured/
  )
})

test('nested readonly/disabled overrides narrow through the core schema form resolver', async() => {
  const loaded = await loadEffectiveAdminConfig({ logLevel: 'silent' })
  let calls = 0
  const resolver = (schema, configuredFields) => {
    calls += 1
    return loaded.schemaFormResolver(schema, configuredFields)
  }
  const fixture = {
    entities: {
      Store: {
        entity: { name: 'Store', plural: 'stores' },
        form: {
          fields: [{
            property: 'settings',
            type: 'json_schema',
            type_options: {
              schema: {
                type: 'object',
                properties: {
                  printing: {
                    type: 'object',
                    properties: {
                      order: {
                        type: 'object',
                        properties: { enabled: { type: 'boolean' } }
                      }
                    }
                  }
                }
              },
              fields: [{
                property: 'printing',
                type_options: {
                  fields: [{
                    property: 'order',
                    type_options: {
                      fields: [{ property: 'enabled', field_options: { disabled: true } }]
                    }
                  }]
                }
              }]
            }
          }]
        },
        list: { disabled_actions: [] }
      }
    },
    routes: [{
      path: '/store',
      name: 'StoreManage',
      children: [{ path: '/store/list', name: 'StoreList' }]
    }]
  }
  const projection = buildAdminConfigProjection(fixture, {
    defaultPrefix: '/api/v1/manage',
    parameterize: name => `${name.toLowerCase()}s`,
    schemaFormResolver: resolver
  })
  assert.ok(calls > 0, 'the shared projector must consume schemaFormResolver')
  const settings = projection.entities
    .find(entity => entity.name === 'Store')
    .actions.update.fields.find(field => field.property === 'settings')
  // Every schema child is disabled, so the root must be omitted rather than
  // exported as an unrestricted {property: 'settings'} field.
  assert.equal(settings, undefined)
})

test('rejects a stale or tampered revision and non-minimal entity data', () => {
  const projector = () => ({
    entities: [{
      key: 'Example',
      name: 'Example',
      actions: {
        create: { configured: true, fields: [{ property: 'name' }] },
        update: { configured: true, fields: [{ property: 'name' }] },
        delete: { configured: false, fields: [] }
      }
    }]
  })
  const manifest = buildAgentManifest({}, { projector })
  const stale = structuredClone(manifest)
  stale.entities[0].actions.create.fields.push({ property: 'extra' })
  assert.throws(() => validateAgentManifest(stale), /revision/i)

  const nonMinimal = structuredClone(manifest)
  nonMinimal.entities[0].label = 'Example'
  assert.throws(() => validateAgentManifest(nonMinimal), /unsupported keys/i)

  const fieldWithLabel = structuredClone(manifest)
  fieldWithLabel.entities[0].actions.create.fields[0].label = 'Name'
  assert.throws(() => validateAgentManifest(fieldWithLabel), /unsupported keys/i)
})

test('atomic write keeps the previous artifact when the write crashes', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    const { manifest } = await generateAgentManifest({ outFile, logLevel: 'silent' })
    const previous = await fsp.readFile(outFile, 'utf8')

    const crashingFileSystem = fakeFileSystem({
      open: async temporary => ({
        writeFile: async() => {
          await fsp.writeFile(temporary, '{"partial":', 'utf8')
          throw new Error('simulated crash during write')
        },
        sync: async() => {},
        close: async() => {}
      })
    })

    await assert.rejects(
      writeAgentManifest(manifest, { outFile, fileSystem: crashingFileSystem }),
      /simulated crash/
    )
    assert.equal(await fsp.readFile(outFile, 'utf8'), previous)
    const entries = await fsp.readdir(directory)
    assert.deepEqual(entries, ['admin-manifest.json'])
  })
})

test('atomic write keeps the previous artifact when rename fails', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    const { manifest } = await generateAgentManifest({ outFile, logLevel: 'silent' })
    const previous = await fsp.readFile(outFile, 'utf8')

    await assert.rejects(
      writeAgentManifest(manifest, {
        outFile,
        fileSystem: fakeFileSystem({ rename: async() => { throw new Error('simulated rename failure') } })
      }),
      /simulated rename failure/
    )
    assert.equal(await fsp.readFile(outFile, 'utf8'), previous)
    const entries = await fsp.readdir(directory)
    assert.deepEqual(entries, ['admin-manifest.json'])
  })
})

test('revocation during the rename window never leaves a stale artifact', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    const manifest = buildAgentManifest({}, {
      projector: () => ({
        entities: [{
          key: 'Example',
          name: 'Example',
          actions: {
            create: { configured: true, fields: [{ property: 'name' }] },
            update: { configured: true, fields: [{ property: 'name' }] },
            delete: { configured: false, fields: [] }
          }
        }]
      })
    })
    const publication = createAgentManifestPublication({ outFile })
    const token = publication.begin()
    const fileSystem = {
      mkdir: fsp.mkdir,
      open: fsp.open,
      rename: async(from, to) => {
        void publication.invalidate()
        await fsp.rename(from, to)
      },
      rm: fsp.rm,
      chmod: fsp.chmod,
      stat: fsp.stat,
      readFile: fsp.readFile
    }

    await assert.rejects(
      writeAgentManifest(manifest, { outFile, fileSystem, publication, token }),
      /superseded/i
    )
    await publication.idle()
    await assert.rejects(fsp.stat(outFile), error => error.code === 'ENOENT')
  })
})

test('a delayed revocation deletion cannot remove a newer publication', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    let rmCalls = 0
    let releaseSecond
    const secondGate = new Promise(resolve => { releaseSecond = resolve })
    const fileSystem = {
      mkdir: fsp.mkdir,
      open: fsp.open,
      rename: fsp.rename,
      chmod: fsp.chmod,
      stat: fsp.stat,
      readFile: fsp.readFile,
      rm: async(target, options) => {
        rmCalls += 1
        if (rmCalls === 2) await secondGate
        return fsp.rm(target, options)
      }
    }
    const publication = createAgentManifestPublication({ outFile, fileSystem })
    const loaded = {
      config: {},
      defaultPrefix: '/api/v1/manage',
      parameterize: name => name.toLowerCase()
    }
    const projector = () => ({
      entities: [{
        key: 'Example',
        name: 'Example',
        actions: {
          create: { configured: true, fields: [{ property: 'newField' }] },
          update: { configured: true, fields: [{ property: 'newField' }] },
          delete: { configured: false, fields: [] }
        }
      }]
    })

    const revocation = publication.invalidate()
    const generation = generateAgentManifest({
      outFile,
      publication,
      loadConfig: async() => loaded,
      projector
    })
    const result = await generation
    if (releaseSecond) releaseSecond()
    await revocation
    await publication.idle()

    const onDisk = JSON.parse(await fsp.readFile(outFile, 'utf8'))
    assert.equal(onDisk.revision, result.manifest.revision)
    assert.deepEqual(onDisk.entities[0].actions.create.fields, [{ property: 'newField' }])
  })
})

test('overlapping generations keep only the newer artifact and never delete it', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    const publication = createAgentManifestPublication({ outFile })
    let releaseFirst
    const firstGate = new Promise(resolve => { releaseFirst = resolve })
    let firstStarted
    const firstStartedGate = new Promise(resolve => { firstStarted = resolve })
    const loaded = {
      config: {},
      defaultPrefix: '/api/v1/manage',
      parameterize: name => name.toLowerCase()
    }
    const projector = property => () => ({
      entities: [{
        key: 'Example',
        name: 'Example',
        actions: {
          create: { configured: true, fields: [{ property }] },
          update: { configured: true, fields: [{ property }] },
          delete: { configured: false, fields: [] }
        }
      }]
    })

    const first = generateAgentManifest({
      outFile,
      publication,
      loadConfig: async() => {
        firstStarted()
        await firstGate
        return loaded
      },
      projector: projector('oldField')
    }).catch(error => error)
    await firstStartedGate
    const second = await generateAgentManifest({
      outFile,
      publication,
      loadConfig: async() => loaded,
      projector: projector('newField')
    })
    releaseFirst()
    const firstResult = await first
    assert.match(firstResult.message, /superseded/i)
    await publication.idle()

    const onDisk = JSON.parse(await fsp.readFile(outFile, 'utf8'))
    assert.equal(onDisk.revision, second.manifest.revision)
    assert.deepEqual(onDisk.entities[0].actions.create.fields, [{ property: 'newField' }])
  })
})

test('generation failure revokes the previous artifact and leaves no fallback', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    await generateAgentManifest({ outFile, logLevel: 'silent' })
    assert.ok((await fsp.stat(outFile)).isFile())

    await assert.rejects(
      generateAgentManifest({
        outFile,
        logLevel: 'silent',
        loadConfig: async() => {
          throw new Error('simulated configuration failure')
        }
      }),
      /simulated configuration failure/
    )
    await assert.rejects(fsp.stat(outFile), error => error.code === 'ENOENT')
    assert.deepEqual(await fsp.readdir(directory), [])
  })
})

test('an empty or invalid projection revokes the artifact', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    await generateAgentManifest({ outFile, logLevel: 'silent' })

    await assert.rejects(
      generateAgentManifest({
        outFile,
        logLevel: 'silent',
        loadConfig: async() => ({
          config: {},
          defaultPrefix: '/api/v1/manage',
          parameterize: name => name.toLowerCase()
        })
      }),
      /no entities/i
    )
    await assert.rejects(fsp.stat(outFile), error => error.code === 'ENOENT')
  })
})

test('manifest plugin fails the build when the artifact cannot be written', async() => {
  await withTemporaryDirectory(async directory => {
    const blocker = path.join(directory, 'blocker')
    await fsp.writeFile(blocker, 'not a directory')
    const plugin = agentManifestPlugin({ outFile: path.join(blocker, 'admin-manifest.json') })
    await assert.rejects(
      plugin.buildStart.call({
        error(message) {
          throw new Error(message)
        }
      }),
      /Admin agent manifest export failed/
    )
  })
})

test('a superseded generation never publishes a stale artifact', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    await generateAgentManifest({ outFile, logLevel: 'silent' })

    await assert.rejects(
      generateAgentManifest({
        outFile,
        logLevel: 'silent',
        beforePublish: () => false
      }),
      /superseded/i
    )
    await assert.rejects(fsp.stat(outFile), error => error.code === 'ENOENT')
    assert.deepEqual(await fsp.readdir(directory), [])
  })
})

test('bindings are evaluated fresh for each generation in the same process', async() => {
  await withTemporaryDirectory(async directory => {
    const loaded = await loadEffectiveAdminConfig({ logLevel: 'silent' })
    const outFile = path.join(directory, 'admin-manifest.json')
    const full = await generateAgentManifest({
      outFile,
      logLevel: 'silent',
      loadConfig: async() => ({ ...loaded })
    })
    assert.deepEqual(
      full.manifest.semanticCapabilities.map(capability => capability.name),
      ['ProductPrinting', 'StorePrinting']
    )

    const withoutStore = { ...loaded.semanticBindings }
    delete withoutStore.StorePrinting
    const narrowed = await generateAgentManifest({
      outFile,
      logLevel: 'silent',
      loadConfig: async() => ({ ...loaded, semanticBindings: withoutStore })
    })
    assert.deepEqual(
      narrowed.manifest.semanticCapabilities.map(capability => capability.name),
      ['ProductPrinting']
    )
    assert.notEqual(full.manifest.revision, narrowed.manifest.revision)
    const onDisk = JSON.parse(await fsp.readFile(outFile, 'utf8'))
    assert.deepEqual(onDisk.semanticCapabilities.map(capability => capability.name), ['ProductPrinting'])
  })
})

test('removeAgentManifest is idempotent', async() => {
  await withTemporaryDirectory(async directory => {
    const outFile = path.join(directory, 'admin-manifest.json')
    await removeAgentManifest({ outFile })
    await removeAgentManifest({ outFile })
    await assert.rejects(fsp.stat(outFile), error => error.code === 'ENOENT')
  })
})

test('real projection accepts the shared projection helper contract', async() => {
  await withTemporaryDirectory(async directory => {
    const loaded = await loadEffectiveAdminConfig({ logLevel: 'silent' })
    const direct = buildAdminConfigProjection(loaded.config, {
      defaultPrefix: loaded.defaultPrefix,
      parameterize: loaded.parameterize,
      semanticBindings: loaded.semanticBindings,
      componentBindings: loaded.componentBindings
    })
    const manifest = buildAgentManifest(loaded.config, {
      defaultPrefix: loaded.defaultPrefix,
      parameterize: loaded.parameterize,
      semanticBindings: loaded.semanticBindings,
      componentBindings: loaded.componentBindings
    })
    assert.equal(manifest.entities.length, direct.entities.length)
    assert.deepEqual(
      manifest.semanticCapabilities.map(capability => capability.name),
      Object.keys(direct.semanticCapabilities).sort()
    )
    assert.deepEqual(
      manifest.entities.map(entity => entity.name).sort(),
      direct.entities.map(entity => entity.name).sort()
    )
    const outFile = path.join(directory, 'admin-manifest.json')
    await writeAgentManifest(manifest, { outFile })
    assert.doesNotThrow(() => validateAgentManifest(JSON.parse(JSON.stringify(manifest))))
  })
})
