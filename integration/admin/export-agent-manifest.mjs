/**
 * Trusted, build-time export of the effective crud-admin configuration for the
 * admin agent backend.
 *
 * The real composed configuration is executed through Vite's SSR module runner.
 * Only browser-only boundaries are replaced (i18n storage, layout/store/router,
 * UI dependencies, request transport). Configuration data, globs, overrides,
 * and merge functions are never replaced or extracted with regexes.
 *
 * The exported manifest is a minimal negative gate: entity -> standard action ->
 * configured writable field, plus bounded semantic binding paths that are
 * derived from the same configured nested JSON schema paths. It is not
 * authorization and contains no routes, URLs, labels, defaults, schemas,
 * callbacks, values, class names, or component source.
 */
import crypto from 'node:crypto'
import fsp from 'node:fs/promises'
import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath, pathToFileURL } from 'node:url'

import { buildAdminConfigProjection } from '../../apps/admin-agent/src/admin-config-policy.js'

const integrationDir = path.dirname(fileURLToPath(import.meta.url))
const repositoryRoot = path.resolve(integrationDir, '../..')
const coreRoot = path.join(repositoryRoot, 'core/crud-admin')
const integrationConfig = path.join(integrationDir, 'vite.config.ts')
const configEntry = path.join(integrationDir, 'config.ts')
const bindingsEntry = path.join(repositoryRoot, 'apps/admin-agent/adapters/ns-ultimate/admin/config-bindings.mjs')
const headlessDir = path.join(integrationDir, 'headless')
const appsAdmin = path.join(repositoryRoot, 'apps/admin-agent')
const businessAdmin = path.join(repositoryRoot, 'business/admin')

const coreRequire = createRequire(path.join(coreRoot, 'package.json'))
const inflect = coreRequire('i')(true)
const manifestFile = path.join(repositoryRoot, 'var/admin-agent/admin-manifest.json')

export const AGENT_MANIFEST_SCHEMA_VERSION = 1
export const AGENT_MANIFEST_SOURCE = 'integration/admin/config.ts'
export const DEFAULT_MANIFEST_PATH = manifestFile
export const HEADLESS_VITE_CACHE_DIR = path.join(repositoryRoot, 'var/admin-agent/vite-headless-cache')
export const MAX_MANIFEST_BYTES = 1024 * 1024
const MAX_ENTITIES = 64
const MAX_FIELDS_PER_ACTION = 256
const MAX_NESTED_PATHS = 128
const MAX_PATH_DEPTH = 8
const MAX_SEMANTIC_CAPABILITIES = 8
const MAX_SEMANTIC_FIELDS = 32
const SAFE_NAME = /^[A-Za-z][A-Za-z0-9_]{0,63}$/
const HASH_PATTERN = /^[0-9a-f]{64}$/
const STANDARD_ACTIONS = ['create', 'update', 'delete']
const UNSAFE_KEYS = new Set(['__proto__', 'prototype', 'constructor'])
const FILTERED_PLUGIN_NAMES = new Set([
  'ns-ultimate-agent-manifest',
  'ns-ultimate-watch-business-admin',
  'ns-ultimate-admin-agent'
])

export function parameterize(name) {
  return inflect.dasherize(inflect.underscore(inflect.pluralize(name)))
}

function isPlainObject(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  const prototype = Object.getPrototypeOf(value)
  return prototype === Object.prototype || prototype === null
}

function aliasSource(alias) {
  return alias.find instanceof RegExp ? alias.find.source : alias.find
}

function existingAliases(config) {
  const raw = config.resolve?.alias ?? {}
  if (Array.isArray(raw)) return raw.filter(alias => alias && typeof alias === 'object')
  return Object.entries(raw).map(([find, replacement]) => ({ find, replacement }))
}

function flattenPlugins(value, output = []) {
  if (!value) return output
  if (Array.isArray(value)) {
    for (const item of value) flattenPlugins(item, output)
    return output
  }
  output.push(value)
  return output
}

async function loadViteApi() {
  let packageFile
  try {
    packageFile = coreRequire.resolve('vite/package.json')
  } catch {
    packageFile = path.join(path.dirname(coreRequire.resolve('vite')), 'package.json')
  }
  const esmEntry = path.join(path.dirname(packageFile), 'dist/node/index.js')
  return import(pathToFileURL(esmEntry).href)
}

function headlessAliases() {
  return [
    { find: /^vue$/, replacement: coreRequire.resolve('vue/dist/vue.runtime.esm-bundler.js') },
    { find: /^vue\/(.*)$/, replacement: path.join(coreRoot, 'node_modules/vue/$1') },
    { find: /^@\/i18n$/, replacement: path.join(headlessDir, 'i18n.mjs') },
    { find: /^@\/layout$/, replacement: path.join(headlessDir, 'layout.mjs') },
    { find: /^@\/utils\/request$/, replacement: path.join(headlessDir, 'request.mjs') },
    { find: /^@\/utils\/simple-image-process$/, replacement: path.join(headlessDir, 'simple-image-process.mjs') },
    { find: /^@\/store$/, replacement: path.join(headlessDir, 'store.mjs') },
    { find: /^@\/router$/, replacement: path.join(headlessDir, 'router.mjs') },
    { find: /^qrcode$/, replacement: path.join(headlessDir, 'qrcode.mjs') },
    { find: /^element-plus$/, replacement: path.join(headlessDir, 'element-plus.mjs') },
    { find: /^pinyin-pro$/, replacement: path.join(headlessDir, 'pinyin-pro.mjs') }
  ]
}

function headlessPlugin() {
  return {
    name: 'ns-ultimate-agent-manifest-headless',
    enforce: 'pre',
    resolveId(id) {
      if (id.endsWith('easyadmin/ui/vue/plugins/list/RelationToOne.vue')) {
        return path.join(headlessDir, 'component.mjs')
      }
      return null
    }
  }
}

/**
 * Create a non-listening Vite server that evaluates the real composed admin
 * config. The caller must close it. The returned server exposes no HTTP
 * listener and has the manifest plugin removed to avoid recursion.
 */
export async function createAgentConfigServer({ mode = 'production', command = 'build', logLevel = 'error' } = {}) {
  const originalWorkingDirectory = process.cwd()
  process.chdir(coreRoot)
  let server

  try {
    const { createServer, loadConfigFromFile } = await loadViteApi()
    const loaded = await loadConfigFromFile(
      { command, mode },
      integrationConfig,
      integrationDir
    )
    if (!loaded?.config) throw new Error('Unable to load integration/admin/vite.config.ts for manifest export.')

    const base = loaded.config
    const aliases = [
      ...headlessAliases(),
      ...existingAliases(base).filter(alias => {
        const source = aliasSource(alias)
        return source !== '^i$' && source !== '^qrcode$'
      })
    ]
    const plugins = [
      headlessPlugin(),
      ...flattenPlugins(base.plugins).filter(plugin => !FILTERED_PLUGIN_NAMES.has(plugin?.name))
    ]

    server = await createServer({
      ...base,
      configFile: false,
      root: coreRoot,
      logLevel,
      appType: 'custom',
      // Never share the browser dev server's dependency optimizer cache. The
      // headless SSR loader writes under ignored project runtime state instead.
      cacheDir: HEADLESS_VITE_CACHE_DIR,
      optimizeDeps: {
        ...base.optimizeDeps,
        noDiscovery: true,
        include: []
      },
      ssr: {
        ...base.ssr,
        optimizeDeps: {
          ...base.ssr?.optimizeDeps,
          noDiscovery: true,
          include: []
        }
      },
      plugins,
      resolve: {
        ...base.resolve,
        alias: aliases
      },
      server: {
        ...base.server,
        middlewareMode: true,
        watch: null,
        hmr: false,
        ws: false,
        open: false,
        fs: {
          ...base.server?.fs,
          allow: [coreRoot, integrationDir, businessAdmin, appsAdmin]
        }
      }
    })

    const close = server.close.bind(server)
    let closed = false
    server.close = async() => {
      if (closed) return
      closed = true
      try {
        await close()
      } finally {
        if (process.cwd() !== originalWorkingDirectory) process.chdir(originalWorkingDirectory)
      }
    }
    return server
  } catch (error) {
    if (server) await server.close().catch(() => {})
    if (process.cwd() !== originalWorkingDirectory) process.chdir(originalWorkingDirectory)
    throw error
  }
}

/**
 * Load the merged effective configuration and the exact identity parameters
 * used by the frontend host.
 *
 * The binding module is loaded through the same fresh SSR server, not imported
 * into the long-lived process, so a binding source edit cannot leave stale
 * grants in memory.
 *
 * @returns {Promise<{
 *   config: object,
 *   defaultPrefix: string,
 *   parameterize: (name: string) => string,
 *   semanticBindings: object,
 *   componentBindings: object,
 *   schemaFormResolver: Function
 * }>}
 */
export async function loadEffectiveAdminConfig(options = {}) {
  const server = await createAgentConfigServer(options)
  try {
    const configModule = await server.ssrLoadModule(configEntry)
    if (!configModule?.default || !isPlainObject(configModule.default)) {
      throw new Error('The composed admin config did not export a configuration object.')
    }
    const prefixModule = await server.ssrLoadModule('@/api/prefix')
    if (typeof prefixModule.API_PREFIX !== 'string' || typeof prefixModule.apiPath !== 'function') {
      throw new Error('The composed admin API prefix could not be resolved.')
    }
    const bindingsModule = await server.ssrLoadModule(bindingsEntry)
    const semanticBindings = bindingsModule?.semanticCapabilityBindings
    const componentBindings = bindingsModule?.scalarComponentBindings
    if (!isPlainObject(semanticBindings) || !isPlainObject(componentBindings)) {
      throw new Error('The host semantic/component bindings could not be resolved.')
    }
    const schemaFormModule = await server.ssrLoadModule('@/utils/json-schema-form.js')
    const schemaFormResolver = schemaFormModule?.createSchemaForm
    if (typeof schemaFormResolver !== 'function') {
      throw new Error('The core JSON-schema form resolver could not be resolved.')
    }
    return {
      config: configModule.default,
      defaultPrefix: prefixModule.apiPath(prefixModule.API_PREFIX, 'manage'),
      parameterize,
      semanticBindings,
      componentBindings,
      schemaFormResolver
    }
  } finally {
    await server.close()
  }
}

function canonicalValue(value) {
  if (Array.isArray(value)) return value.map(canonicalValue)
  if (isPlainObject(value)) {
    const output = {}
    for (const key of Object.keys(value).sort()) {
      if (value[key] !== undefined) output[key] = canonicalValue(value[key])
    }
    return output
  }
  if (typeof value === 'number' && !Number.isFinite(value)) {
    throw new TypeError('The manifest contains a non-finite number.')
  }
  if (typeof value === 'function' || typeof value === 'bigint' || typeof value === 'symbol' || value === undefined) {
    throw new TypeError('The manifest contains a non-serializable value.')
  }
  return value
}

function revisionOf(payload) {
  return crypto.createHash('sha256').update(JSON.stringify(canonicalValue(payload))).digest('hex')
}

function safePathSegments(value) {
  if (typeof value !== 'string' || value.length === 0) return null
  const segments = value.split('.')
  if (segments.length === 0 || segments.length > MAX_PATH_DEPTH) return null
  if (segments.some(segment => !SAFE_NAME.test(segment) || UNSAFE_KEYS.has(segment))) return null
  return segments
}

function minimalField(field) {
  if (!isPlainObject(field) || typeof field.property !== 'string' || !SAFE_NAME.test(field.property)) {
    throw new TypeError('The projection contains an invalid configured field.')
  }
  const output = { property: field.property }
  const sourcePaths = field.sourcePaths
  if (sourcePaths !== undefined) {
    if (!Array.isArray(sourcePaths) || sourcePaths.length > MAX_NESTED_PATHS) {
      throw new RangeError(`The ${field.property} source path list is out of bounds.`)
    }
    const seen = new Set()
    const nestedPaths = []
    for (const sourcePath of sourcePaths) {
      const segments = safePathSegments(sourcePath)
      if (!segments) throw new TypeError(`The ${field.property} source path is unsafe.`)
      const normalized = segments.join('.')
      if (normalized === field.property || !normalized.startsWith(`${field.property}.`)) {
        throw new TypeError(`The ${field.property} source path is not qualified.`)
      }
      if (seen.has(normalized)) continue
      seen.add(normalized)
      nestedPaths.push(normalized)
    }
    if (nestedPaths.length) output.nestedPaths = nestedPaths
  }
  return output
}

function minimalAction(entityName, actionName, action) {
  if (!isPlainObject(action) || typeof action.configured !== 'boolean' || !Array.isArray(action.fields)) {
    throw new TypeError(`The projection contains an invalid ${actionName} action for ${entityName}.`)
  }
  if (action.fields.length > MAX_FIELDS_PER_ACTION) {
    throw new RangeError(`The ${entityName}.${actionName} field list exceeds the manifest bound.`)
  }
  const seen = new Set()
  const fields = []
  for (const field of action.fields) {
    const minimal = minimalField(field)
    if (seen.has(minimal.property)) continue
    seen.add(minimal.property)
    fields.push(minimal)
  }
  return { configured: action.configured, fields }
}

function minimalEntity(entity) {
  if (!isPlainObject(entity) || typeof entity.key !== 'string' || typeof entity.name !== 'string') {
    throw new TypeError('The projection contains an invalid entity.')
  }
  if (!SAFE_NAME.test(entity.key) || !SAFE_NAME.test(entity.name)) {
    throw new TypeError('The projection contains an unsafe entity name.')
  }
  if (!isPlainObject(entity.actions)) {
    throw new TypeError(`The projection for ${entity.name} has no action metadata.`)
  }
  const actions = {}
  for (const actionName of STANDARD_ACTIONS) {
    actions[actionName] = minimalAction(entity.name, actionName, entity.actions[actionName])
  }
  return { key: entity.key, name: entity.name, actions }
}

function semanticField(targetEntity, actions, runtimeProperty, configuredPath) {
  if (!SAFE_NAME.test(runtimeProperty) || UNSAFE_KEYS.has(runtimeProperty)) {
    throw new TypeError('A semantic capability contains an unsafe field name.')
  }
  const segments = safePathSegments(configuredPath)
  if (!segments) throw new TypeError(`Semantic path ${configuredPath} is unsafe or too deep.`)
  const topLevel = segments[0]
  const normalizedPath = segments.join('.')

  let absoluteNestedPaths = null
  for (const actionName of actions) {
    const action = targetEntity.actions[actionName]
    if (!action?.configured) {
      throw new Error(`The semantic path ${configuredPath} targets an unconfigured action.`)
    }
    const configured = action.fields.find(field => field.property === topLevel)
    if (!configured) {
      throw new Error(`The semantic path ${configuredPath} is not in ${targetEntity.name}.${actionName} fields.`)
    }
    const sourcePaths = configured.nestedPaths || []
    if (segments.length > 1 && !sourcePaths.includes(normalizedPath)) {
      throw new Error(`The semantic path ${configuredPath} is not configured in ${targetEntity.name}.${actionName}.`)
    }
    // Wire nestedPaths are absolute configured descendants. Preserve source
    // case and include only paths below the alias root, never the root itself.
    const descendants = sourcePaths.filter(path => path.startsWith(`${normalizedPath}.`))
    absoluteNestedPaths = absoluteNestedPaths === null
      ? descendants
      : absoluteNestedPaths.filter(path => descendants.includes(path))
  }

  const output = {
    property: runtimeProperty,
    path: normalizedPath
  }
  const nestedPaths = [...new Set(absoluteNestedPaths || [])]
  if (nestedPaths.length) {
    if (nestedPaths.length > MAX_NESTED_PATHS) throw new RangeError(`Semantic path ${configuredPath} has too many nested paths.`)
    output.nestedPaths = nestedPaths
  }
  return output
}

function minimalSemanticCapabilities(projection, entitiesByName) {
  const raw = projection.semanticCapabilities
  if (raw === undefined) return []
  if (!isPlainObject(raw)) throw new TypeError('The projection semantic capabilities are invalid.')
  const names = Object.keys(raw).sort()
  if (names.length > MAX_SEMANTIC_CAPABILITIES) throw new RangeError('The semantic capability list is out of bounds.')

  const result = []
  for (const name of names) {
    if (!SAFE_NAME.test(name) || UNSAFE_KEYS.has(name)) throw new TypeError('A semantic capability name is unsafe.')
    const binding = raw[name]
    assertExactKeys(binding, ['entity', 'actions', 'fields'], 'Semantic capability')
    if (typeof binding.entity !== 'string' || !entitiesByName.has(binding.entity)) {
      throw new Error(`The semantic capability ${name} targets an unknown entity.`)
    }
    if (!Array.isArray(binding.actions) || binding.actions.length === 0
      || binding.actions.some(action => !['create', 'update'].includes(action))
      || new Set(binding.actions).size !== binding.actions.length) {
      throw new TypeError(`The semantic capability ${name} has invalid actions.`)
    }
    if (!isPlainObject(binding.fields) || Object.keys(binding.fields).length === 0
      || Object.keys(binding.fields).length > MAX_SEMANTIC_FIELDS) {
      throw new RangeError(`The semantic capability ${name} has an invalid field map.`)
    }

    const target = entitiesByName.get(binding.entity)
    const fields = []
    for (const [runtimeProperty, configuredPath] of Object.entries(binding.fields)) {
      fields.push(semanticField(target, binding.actions, runtimeProperty, configuredPath))
    }
    fields.sort((left, right) => left.property < right.property ? -1 : left.property > right.property ? 1 : 0)
    result.push({
      name,
      entity: binding.entity,
      actions: [...binding.actions],
      fields
    })
  }
  return result
}

/**
 * Build the minimal, deterministic manifest from the shared pure projection.
 * This function does not call callbacks and does not read the filesystem.
 */
export function buildAgentManifest(adminConfig, {
  defaultPrefix = '/api/v1/manage',
  parameterize: parameterizeName = parameterize,
  projector = buildAdminConfigProjection,
  semanticBindings = {},
  componentBindings = {},
  schemaFormResolver
} = {}) {
  if (typeof projector !== 'function') throw new TypeError('A config projector function is required.')
  if (typeof defaultPrefix !== 'string' || !defaultPrefix.startsWith('/')) {
    throw new TypeError('A safe default API prefix is required.')
  }
  if (typeof parameterizeName !== 'function') throw new TypeError('A parameterize function is required.')

  const projection = projector(adminConfig, {
    defaultPrefix,
    parameterize: parameterizeName,
    semanticBindings,
    componentBindings,
    schemaFormResolver
  })
  if (!projection || !Array.isArray(projection.entities)) {
    throw new TypeError('The config projector returned no entity list.')
  }
  if (projection.entities.length === 0) {
    throw new Error('The effective admin configuration contains no entities.')
  }
  if (projection.entities.length > MAX_ENTITIES) {
    throw new RangeError('The effective admin configuration exceeds the entity bound.')
  }

  const entities = projection.entities.map(minimalEntity)
  entities.sort((left, right) => {
    if (left.name !== right.name) return left.name < right.name ? -1 : 1
    if (left.key !== right.key) return left.key < right.key ? -1 : 1
    return 0
  })

  const names = new Set()
  const keys = new Set()
  for (const entity of entities) {
    if (names.has(entity.name) || keys.has(entity.key)) {
      throw new Error(`The effective admin configuration contains a duplicate entity: ${entity.name}.`)
    }
    names.add(entity.name)
    keys.add(entity.key)
  }

  const entitiesByName = new Map(entities.map(entity => [entity.name, entity]))
  const semanticCapabilities = minimalSemanticCapabilities(projection, entitiesByName)

  const payload = {
    schemaVersion: AGENT_MANIFEST_SCHEMA_VERSION,
    source: AGENT_MANIFEST_SOURCE,
    entities,
    semanticCapabilities
  }
  return {
    schemaVersion: AGENT_MANIFEST_SCHEMA_VERSION,
    revision: revisionOf(payload),
    source: AGENT_MANIFEST_SOURCE,
    entities,
    semanticCapabilities
  }
}

function assertExactKeys(value, expected, label) {
  if (!isPlainObject(value)) throw new TypeError(`${label} must be a plain object.`)
  const keys = Object.keys(value).sort()
  const wanted = [...expected].sort()
  if (keys.length !== wanted.length || keys.some((key, index) => key !== wanted[index])) {
    throw new TypeError(`${label} contains unsupported keys.`)
  }
}

/**
 * Validate the minimal shape, bounds, and revision of a manifest. Throws when
 * the artifact is malformed or has been altered.
 */
function validateField(field, label) {
  const keys = Object.keys(field).sort()
  const allowed = keys.length === 1 && keys[0] === 'property'
    || keys.length === 2 && keys[0] === 'nestedPaths' && keys[1] === 'property'
  if (!allowed) throw new TypeError(`${label} contains unsupported keys.`)
  if (!SAFE_NAME.test(field.property)) throw new TypeError(`${label} property is unsafe.`)
  if (field.nestedPaths === undefined) return
  if (!Array.isArray(field.nestedPaths) || field.nestedPaths.length > MAX_NESTED_PATHS) {
    throw new RangeError(`${label} nested path list is out of bounds.`)
  }
  const seen = new Set()
  for (const nestedPath of field.nestedPaths) {
    const segments = safePathSegments(nestedPath)
    if (!segments) throw new TypeError(`${label} contains an unsafe nested path.`)
    const normalized = segments.join('.')
    if (normalized === field.property || !normalized.startsWith(`${field.property}.`)) {
      throw new TypeError(`${label} contains a non-qualified nested path.`)
    }
    if (seen.has(normalized)) throw new Error(`${label} contains a duplicate nested path.`)
    seen.add(normalized)
  }
}

export function validateAgentManifest(manifest) {
  assertExactKeys(manifest, ['schemaVersion', 'revision', 'source', 'entities', 'semanticCapabilities'], 'Manifest')
  if (manifest.schemaVersion !== AGENT_MANIFEST_SCHEMA_VERSION) throw new Error('Unsupported manifest schema version.')
  if (manifest.source !== AGENT_MANIFEST_SOURCE) throw new Error('Unexpected manifest source.')
  if (typeof manifest.revision !== 'string' || !HASH_PATTERN.test(manifest.revision)) {
    throw new TypeError('The manifest revision is invalid.')
  }
  if (!Array.isArray(manifest.entities) || manifest.entities.length === 0 || manifest.entities.length > MAX_ENTITIES) {
    throw new RangeError('The manifest entity list is out of bounds.')
  }

  const names = new Set()
  const keys = new Set()
  const entitiesByName = new Map()
  for (const entity of manifest.entities) {
    assertExactKeys(entity, ['key', 'name', 'actions'], 'Manifest entity')
    if (!SAFE_NAME.test(entity.key) || !SAFE_NAME.test(entity.name)) throw new TypeError('The manifest entity name is unsafe.')
    if (names.has(entity.name) || keys.has(entity.key)) throw new Error('The manifest contains a duplicate entity.')
    names.add(entity.name)
    keys.add(entity.key)
    entitiesByName.set(entity.name, entity)
    assertExactKeys(entity.actions, STANDARD_ACTIONS, 'Manifest entity actions')
    for (const actionName of STANDARD_ACTIONS) {
      const action = entity.actions[actionName]
      assertExactKeys(action, ['configured', 'fields'], 'Manifest action')
      if (typeof action.configured !== 'boolean') throw new TypeError('The manifest action flag is invalid.')
      if (!Array.isArray(action.fields) || action.fields.length > MAX_FIELDS_PER_ACTION) {
        throw new RangeError('The manifest action field list is out of bounds.')
      }
      const properties = new Set()
      for (const field of action.fields) {
        validateField(field, 'Manifest field')
        if (properties.has(field.property)) throw new Error('The manifest action contains a duplicate field.')
        properties.add(field.property)
      }
    }
  }

  if (!Array.isArray(manifest.semanticCapabilities)
    || manifest.semanticCapabilities.length > MAX_SEMANTIC_CAPABILITIES) {
    throw new RangeError('The manifest semantic capability list is out of bounds.')
  }
  const semanticNames = new Set()
  let previousName = ''
  for (const capability of manifest.semanticCapabilities) {
    assertExactKeys(capability, ['name', 'entity', 'actions', 'fields'], 'Manifest semantic capability')
    if (!SAFE_NAME.test(capability.name) || UNSAFE_KEYS.has(capability.name)) {
      throw new TypeError('The manifest semantic capability name is unsafe.')
    }
    if (capability.name < previousName) throw new Error('The manifest semantic capabilities are not sorted.')
    previousName = capability.name
    if (semanticNames.has(capability.name)) throw new Error('The manifest contains a duplicate semantic capability.')
    semanticNames.add(capability.name)
    const target = entitiesByName.get(capability.entity)
    if (!target) throw new Error(`The manifest semantic capability ${capability.name} targets an unknown entity.`)
    if (!Array.isArray(capability.actions) || capability.actions.length === 0
      || capability.actions.some(action => !['create', 'update'].includes(action))
      || new Set(capability.actions).size !== capability.actions.length) {
      throw new TypeError(`The manifest semantic capability ${capability.name} has invalid actions.`)
    }
    if (!Array.isArray(capability.fields) || capability.fields.length === 0
      || capability.fields.length > MAX_SEMANTIC_FIELDS) {
      throw new RangeError(`The manifest semantic capability ${capability.name} field list is out of bounds.`)
    }
    const runtimeFields = new Set()
    for (const field of capability.fields) {
      const fieldKeys = Object.keys(field).sort()
      const allowed = fieldKeys.length === 2 && fieldKeys[0] === 'path' && fieldKeys[1] === 'property'
        || fieldKeys.length === 3 && fieldKeys[0] === 'nestedPaths' && fieldKeys[1] === 'path' && fieldKeys[2] === 'property'
      if (!allowed) throw new TypeError('A manifest semantic field contains unsupported keys.')
      if (!SAFE_NAME.test(field.property) || UNSAFE_KEYS.has(field.property)) {
        throw new TypeError('A manifest semantic field property is unsafe.')
      }
      if (runtimeFields.has(field.property)) throw new Error('A manifest semantic capability contains a duplicate field.')
      runtimeFields.add(field.property)
      const expected = semanticField(target, capability.actions, field.property, field.path)
      if (JSON.stringify(expected) !== JSON.stringify(field)) {
        throw new Error(`The manifest semantic field ${capability.name}.${field.property} does not match the configured path coverage.`)
      }
    }
  }

  const expectedRevision = revisionOf({
    schemaVersion: manifest.schemaVersion,
    source: manifest.source,
    entities: manifest.entities,
    semanticCapabilities: manifest.semanticCapabilities
  })
  const left = Buffer.from(manifest.revision, 'hex')
  const right = Buffer.from(expectedRevision, 'hex')
  if (left.length !== right.length || !crypto.timingSafeEqual(left, right)) {
    throw new Error('The manifest revision does not match its semantic content.')
  }
  if (Buffer.byteLength(serializeAgentManifest(manifest), 'utf8') > MAX_MANIFEST_BYTES) {
    throw new RangeError('The manifest exceeds the artifact size bound.')
  }
  return true
}

function orderedManifest(manifest) {
  return {
    schemaVersion: manifest.schemaVersion,
    revision: manifest.revision,
    source: manifest.source,
    entities: manifest.entities.map(entity => ({
      key: entity.key,
      name: entity.name,
      actions: Object.fromEntries(STANDARD_ACTIONS.map(actionName => [
        actionName,
        {
          configured: entity.actions[actionName].configured,
          fields: entity.actions[actionName].fields.map(field => ({
            property: field.property,
            ...(field.nestedPaths ? { nestedPaths: [...field.nestedPaths] } : {})
          }))
        }
      ]))
    })),
    semanticCapabilities: manifest.semanticCapabilities.map(capability => ({
      name: capability.name,
      entity: capability.entity,
      actions: [...capability.actions],
      fields: capability.fields.map(field => ({
        property: field.property,
        path: field.path,
        ...(field.nestedPaths ? { nestedPaths: [...field.nestedPaths] } : {})
      }))
    }))
  }
}

/** Serialize a manifest deterministically. Validation is separate. */
export function serializeAgentManifest(manifest) {
  return `${JSON.stringify(orderedManifest(manifest), null, 2)}\n`
}

function supersededError() {
  return new Error('Manifest publish was superseded by a newer configuration change.')
}

async function removeIfOwned({ outFile, fileSystem, snapshot, revision }) {
  try {
    if (snapshot && typeof fileSystem.stat === 'function') {
      const current = await fileSystem.stat(outFile)
      if (current.dev !== snapshot.dev || current.ino !== snapshot.ino) return false
    }
    if (typeof fileSystem.readFile === 'function') {
      const text = await fileSystem.readFile(outFile, 'utf8')
      let parsed
      try { parsed = JSON.parse(text) } catch { return false }
      if (parsed.revision !== revision) return false
    }
    await fileSystem.rm(outFile, { force: true })
    return true
  } catch {
    return false
  }
}

/**
 * Shared publication/revocation controller.
 *
 * Revocation increments the epoch synchronously, removes the artifact
 * immediately, and enqueues a cleanup behind any in-flight publish. A publish
 * checks its epoch before writing, before rename, and after rename. If a
 * revocation lands during the rename window, the publication removes only the
 * file it just published (inode + revision), so it cannot delete a newer valid
 * artifact. Publication and cleanup tasks share one FIFO queue.
 */
export function createAgentManifestPublication({
  outFile = DEFAULT_MANIFEST_PATH,
  fileSystem = fsp
} = {}) {
  let epoch = 0
  let queue = Promise.resolve()

  const run = task => {
    const result = queue.then(task, task)
    queue = result.then(() => {}, () => {})
    return result
  }

  const begin = () => {
    epoch += 1
    return epoch
  }

  const invalidate = () => {
    epoch += 1
    // Every removal is a FIFO task. There is no out-of-band deletion that
    // could resume later and remove a newer publication's inode.
    return run(async() => {
      await fileSystem.rm(outFile, { force: true })
    }).then(() => epoch)
  }

  const cancel = token => token === epoch ? invalidate() : Promise.resolve(false)
  const withLock = (token, task) => run(async() => {
    if (token !== epoch) throw supersededError()
    return task()
  })

  return {
    outFile,
    fileSystem,
    begin,
    invalidate,
    revoke: invalidate,
    cancel,
    currentEpoch: () => epoch,
    isCurrent: token => token === epoch,
    withLock,
    idle: () => queue
  }
}

/**
 * Atomically replace the artifact. The target is never partially visible: the
 * complete payload is written and synced to a sibling temporary file, then
 * renamed over the target. A crash or failure leaves either the previous valid
 * artifact or no artifact, never a partial file at the published path.
 *
 * With a publication controller, a revocation that lands between the final
 * freshness check and rename is detected after rename and removes only this
 * publication's own inode/revision.
 */
export async function writeAgentManifest(manifest, {
  outFile,
  fileSystem,
  publication,
  token,
  beforePublish
} = {}) {
  validateAgentManifest(manifest)
  const target = outFile ?? publication?.outFile ?? DEFAULT_MANIFEST_PATH
  const files = fileSystem ?? publication?.fileSystem ?? fsp
  const text = serializeAgentManifest(manifest)
  const directory = path.dirname(target)
  const temporary = path.join(
    directory,
    `.${path.basename(target)}.${process.pid}.${crypto.randomBytes(8).toString('hex')}.tmp`
  )

  const execute = async() => {
    await files.mkdir(directory, { recursive: true, mode: 0o700 })
    if (typeof files.chmod === 'function') {
      try { await files.chmod(directory, 0o700) } catch { /* best effort on pre-existing dirs */ }
    }
    let handle
    try {
      handle = await files.open(temporary, 'wx', 0o600)
      await handle.writeFile(text, 'utf8')
      await handle.sync()
      await handle.close()
      handle = null
      if (publication && !publication.isCurrent(token)) {
        await files.rm(temporary, { force: true })
        throw supersededError()
      }
      if (typeof beforePublish === 'function' && await beforePublish() === false) {
        await files.rm(temporary, { force: true })
        throw supersededError()
      }
      if (publication && !publication.isCurrent(token)) {
        await files.rm(temporary, { force: true })
        throw supersededError()
      }
      await files.rename(temporary, target)
      if (typeof files.chmod === 'function') {
        try { await files.chmod(target, 0o600) } catch { /* best effort */ }
      }
      if (publication) {
        const snapshot = typeof files.stat === 'function'
          ? await files.stat(target).catch(() => null)
          : null
        if (!publication.isCurrent(token)) {
          await removeIfOwned({ outFile: target, fileSystem: files, snapshot, revision: manifest.revision })
          throw supersededError()
        }
      }
      try {
        const directoryHandle = await files.open(directory, 'r')
        await directoryHandle.sync()
        await directoryHandle.close()
      } catch {
        // Directory fsync is best-effort; the rename is still atomic.
      }
    } catch (error) {
      if (handle) await handle.close().catch(() => {})
      await files.rm(temporary, { force: true }).catch(() => {})
      throw error
    }
  }

  if (publication) {
    if (!Number.isInteger(token)) throw new TypeError('A publication token is required.')
    return publication.withLock(token, execute)
  }
  return execute()
}

/** Remove the artifact. Used on every generation failure to fail closed. */
export async function removeAgentManifest({
  outFile = DEFAULT_MANIFEST_PATH,
  fileSystem = fsp
} = {}) {
  await fileSystem.rm(outFile, { force: true })
}

/**
 * Load, project, validate, and atomically write the manifest. On any failure
 * the current generation's artifact is revoked before the error is rethrown.
 * A superseded generation never revokes a newer publication.
 */
export async function generateAgentManifest(options = {}) {
  const outFile = options.outFile ?? options.publication?.outFile ?? DEFAULT_MANIFEST_PATH
  const publication = options.publication ?? createAgentManifestPublication({
    outFile,
    fileSystem: options.fileSystem ?? fsp
  })
  const token = publication.begin()
  try {
    const loaded = typeof options.loadConfig === 'function'
      ? await options.loadConfig()
      : await loadEffectiveAdminConfig(options)
    const manifest = buildAgentManifest(loaded.config, {
      defaultPrefix: loaded.defaultPrefix,
      parameterize: loaded.parameterize,
      projector: options.projector,
      semanticBindings: options.semanticBindings ?? loaded.semanticBindings,
      componentBindings: options.componentBindings ?? loaded.componentBindings,
      schemaFormResolver: options.schemaFormResolver ?? loaded.schemaFormResolver
    })
    await writeAgentManifest(manifest, {
      outFile,
      fileSystem: options.fileSystem ?? publication.fileSystem,
      publication,
      token,
      beforePublish: options.beforePublish
    })
    return { manifest, outFile }
  } catch (error) {
    await publication.cancel(token).catch(() => {})
    throw error
  }
}
