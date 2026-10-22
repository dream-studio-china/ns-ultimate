const SAFE_LOCAL_PATH = /^\/(?:[A-Za-z0-9._~-]+\/?)*$/
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

function normalizePath(path, parentPath) {
  if (typeof path !== 'string' || !path || path.includes('?') || path.includes('#') || path.includes('\\')) return null
  const fullPath = path.startsWith('/') ? path : `${parentPath.replace(/\/$/, '')}/${path}`
  if (!SAFE_LOCAL_PATH.test(fullPath) || fullPath.includes('..') || fullPath.includes('//')) return null
  return fullPath.length > 1 ? fullPath.replace(/\/$/, '') : fullPath
}

export function buildAdminPageCatalog(routes) {
  if (!Array.isArray(routes)) return []
  const pages = []
  const names = new Set()

  const visit = (entries, parentPath = '/', hiddenParent = false) => {
    for (const route of entries) {
      if (!route || typeof route !== 'object') continue
      const hidden = hiddenParent || route.hidden === true
      const path = typeof route.redirect === 'string' && route.redirect !== 'noRedirect'
        ? normalizePath(route.redirect, parentPath)
        : normalizePath(route.path, parentPath)

      if (Array.isArray(route.children) && route.children.length) {
        visit(route.children, path || parentPath, hidden)
        continue
      }
      if (hidden || typeof route.name !== 'string' || names.has(route.name)) continue
      // The assistant may open registered collection pages only. It never
      // exposes create, update, detail, or arbitrary route destinations.
      if (!route.name.endsWith('List') || !path?.endsWith('/list')) continue
      names.add(route.name)
      const title = typeof route.meta?.title === 'string' ? route.meta.title : route.name.slice(0, -4)
      pages.push({ key: route.name, title: title.slice(0, 120), path })
    }
  }

  visit(routes)
  return pages
}

export function resolveAdminNavigation(pages, pageKey, identifier) {
  if (typeof pageKey !== 'string') throw new TypeError('Choose a registered admin list page.')
  const page = pages.find(item => item.key === pageKey)
  if (!page) throw new Error('This admin page is not in the navigation allowlist.')
  if (identifier === undefined || identifier === null || identifier === '') return { ...page }

  let safeIdentifier
  if (typeof identifier === 'number' && Number.isSafeInteger(identifier) && identifier > 0) {
    safeIdentifier = String(identifier)
  } else if (typeof identifier === 'string' && (/^[1-9]\d{0,17}$/.test(identifier) || UUID.test(identifier))) {
    safeIdentifier = identifier
  } else {
    throw new TypeError('Record identifiers must be a positive numeric ID or UUID.')
  }

  return { ...page, path: `${page.path.slice(0, -'/list'.length)}/${safeIdentifier}/detail`, identifier: safeIdentifier }
}
