import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import adminViteConfig from './vite.config'

// Mounts the composed Accounting admin (business configs + real EasyAdmin
// components) in jsdom. Run from core/crud-admin:
//   npx vitest run --config ../../integration/admin/accounting.vitest.config.ts
const integrationDir = path.dirname(fileURLToPath(import.meta.url))
const repositoryRoot = path.resolve(integrationDir, '../..')
const coreRoot = path.join(repositoryRoot, 'core/crud-admin')
const coreRequire = createRequire(path.join(coreRoot, 'package.json'))
// The spec lives outside the core package; resolve its test-only imports there.
const testAliases = [
  { find: /^@vue\/test-utils$/, replacement: coreRequire.resolve('@vue/test-utils') },
  { find: /^element-plus$/, replacement: path.join(coreRoot, 'node_modules/element-plus/es/index.mjs') },
  { find: /^vue$/, replacement: path.join(coreRoot, 'node_modules/vue/index.mjs') }
]

export default (env: { mode: string; command: string }) => {
  const config = adminViteConfig({ ...env, mode: 'test', command: 'serve' })
  return {
    ...config,
    resolve: { ...config.resolve, alias: [...testAliases, ...(config.resolve?.alias ?? [])] },
    plugins: (config.plugins ?? []).filter(plugin => !['watch-business-admin', 'admin-agent'].some(name => String((plugin as { name?: string })?.name ?? '').includes(name))),
    test: {
      environment: 'jsdom',
      globals: true,
      root: repositoryRoot,
      include: ['integration/admin/accounting-resource.spec.js'],
      server: { deps: { inline: [/element-plus/] } }
    }
  }
}
