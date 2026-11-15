import assert from 'node:assert/strict'
import test from 'node:test'
import { readdirSync, readFileSync, statSync } from 'node:fs'

const root = new URL('../../', import.meta.url)

function sources(directory) {
  const files = []
  const walk = path => {
    for (const entry of readdirSync(path)) {
      if (['node_modules', 'dist', 'tests'].includes(entry)) continue
      const url = new URL(`${entry}`, path)
      if (statSync(url).isDirectory()) walk(new URL(`${entry}/`, path))
      else if (['.vue', '.js', '.jsx', '.ts'].includes(entry.slice(entry.lastIndexOf('.')))) files.push(url)
    }
  }
  walk(directory)
  return files
}

test('every legacy icon name used by business components is registered by the core', () => {
  const registry = readFileSync(new URL('core/crud-admin/src/icons/legacy-icons.js', root), 'utf8')
  const registered = new Set([...registry.matchAll(/'([^']+)':/g)].map(match => match[1]))
  assert.ok(registered.has('el-icon-document-copy'))

  const unknown = []
  for (const file of [...sources(new URL('business/admin/', root)), ...sources(new URL('integration/admin/', root))]) {
    if (file.pathname.endsWith('.test.mjs')) continue
    for (const match of readFileSync(file, 'utf8').matchAll(/icon=["'](el-icon-[a-z0-9-]+)["']/g)) {
      if (!registered.has(match[1])) unknown.push(`${file.pathname.split('/ns-ultimate/')[1]}: ${match[1]}`)
    }
  }

  assert.deepEqual(unknown, [])
})
