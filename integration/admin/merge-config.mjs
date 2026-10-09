function assertOperationList(value, label) {
  if (!Array.isArray(value)) {
    throw new TypeError(`${label} must be an array.`)
  }
}

function normalizedPath(path) {
  return `/${path}`
    .replace(/\/+/g, '/')
    .replace(/:[^/()]+(?:\([^)]*\))?[?*]?/g, ':')
    .replace(/\/$/, '') || '/'
}

function routePath(parentPath, route) {
  if (typeof route.path !== 'string') return null
  if (route.path.startsWith('/')) return normalizedPath(route.path)
  return normalizedPath(`${parentPath}/${route.path}`)
}

function collectRouteKeys(routes, parentPath = '/', names = new Set(), paths = new Set()) {
  for (const route of routes) {
    if (!route || typeof route !== 'object') {
      throw new TypeError('Every route must be an object.')
    }

    if (route.name !== undefined) {
      if (names.has(route.name)) {
        throw new Error(`Duplicate route name "${route.name}".`)
      }
      names.add(route.name)
    }

    const path = routePath(parentPath, route)
    if (path !== null) {
      if (paths.has(path)) {
        throw new Error(`Duplicate route path "${path}".`)
      }
      paths.add(path)
    }

    if (route.children !== undefined) {
      if (!Array.isArray(route.children)) {
        throw new TypeError('Route children must be an array.')
      }
      collectRouteKeys(route.children, path ?? parentPath, names, paths)
    }
  }

  return { names, paths }
}

/**
 * Add, replace, and remove top-level menu groups without mutating core routes.
 * Replacements must preserve the existing top-level route name.
 */
export function mergeRoutes(coreRoutes, operations = {}) {
  assertOperationList(coreRoutes, 'Core routes')
  const additions = operations.add ?? []
  const replacements = operations.replace ?? []
  const removals = operations.remove ?? []
  assertOperationList(additions, 'Route additions')
  assertOperationList(replacements, 'Route replacements')
  assertOperationList(removals, 'Route removals')

  const topLevelByName = new Map()
  for (const route of coreRoutes) {
    if (typeof route.name !== 'string' || route.name.length === 0) continue
    if (topLevelByName.has(route.name)) {
      throw new Error(`Core has duplicate top-level route name "${route.name}".`)
    }
    topLevelByName.set(route.name, route)
  }

  const replacedNames = new Set()
  for (const route of replacements) {
    if (typeof route?.name !== 'string' || !topLevelByName.has(route.name)) {
      throw new Error(`Cannot replace unknown top-level route "${route?.name ?? ''}".`)
    }
    replacedNames.add(route.name)
  }

  const removedNames = new Set(removals)
  if ([...removedNames].some(name => typeof name !== 'string')) {
    throw new TypeError('Route removals must contain route-name strings.')
  }
  for (const name of removedNames) {
    if (!topLevelByName.has(name)) {
      throw new Error(`Cannot remove unknown top-level route "${name}".`)
    }
    if (replacedNames.has(name)) {
      throw new Error(`Route "${name}" cannot be replaced and removed together.`)
    }
  }

  const retainedCoreRoutes = coreRoutes.filter(route =>
    !replacedNames.has(route.name) && !removedNames.has(route.name)
  )
  const routes = [...retainedCoreRoutes, ...replacements, ...additions]
  collectRouteKeys(routes)
  return routes
}

/** Merge flat entity configuration keys with explicit replacement/removal. */
export function mergeEntities(coreEntities, operations = {}) {
  if (!coreEntities || typeof coreEntities !== 'object' || Array.isArray(coreEntities)) {
    throw new TypeError('Core entities must be an object.')
  }

  const additions = operations.add ?? {}
  const replacements = operations.replace ?? {}
  const removals = operations.remove ?? []
  for (const [label, value] of [['Entity additions', additions], ['Entity replacements', replacements]]) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      throw new TypeError(`${label} must be an object.`)
    }
  }
  assertOperationList(removals, 'Entity removals')

  const result = { ...coreEntities }
  for (const name of Object.keys(additions)) {
    if (Object.hasOwn(coreEntities, name)) {
      throw new Error(`Entity "${name}" already exists; declare it as a replacement explicitly.`)
    }
    result[name] = additions[name]
  }

  for (const [name, config] of Object.entries(replacements)) {
    if (!Object.hasOwn(coreEntities, name)) {
      throw new Error(`Cannot replace unknown core entity "${name}".`)
    }
    result[name] = config
  }

  for (const name of removals) {
    if (typeof name !== 'string' || !Object.hasOwn(coreEntities, name)) {
      throw new Error(`Cannot remove unknown core entity "${name}".`)
    }
    if (Object.hasOwn(replacements, name)) {
      throw new Error(`Entity "${name}" cannot be replaced and removed together.`)
    }
    delete result[name]
  }

  return result
}
