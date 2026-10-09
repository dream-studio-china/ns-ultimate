import path from 'node:path'
import { fileURLToPath } from 'node:url'
import coreViteConfig from '../../core/crud-admin/vite.config'

const integrationDir = path.dirname(fileURLToPath(import.meta.url))
const repositoryRoot = path.resolve(integrationDir, '../..')
const coreRoot = path.join(repositoryRoot, 'core/crud-admin')
const coreSource = path.join(coreRoot, 'src')
const injectedConfig = path.join(integrationDir, 'config.ts')
const businessAdmin = path.join(repositoryRoot, 'business/admin')

export default (configEnv: { mode: string; command: string }) => {
  const originalWorkingDirectory = process.cwd()
  let coreConfig

  try {
    // The upstream config loads its .env files from process.cwd().
    // Use project-owned defaults and overrides, never upstream env values.
    process.chdir(integrationDir)
    coreConfig = coreViteConfig(configEnv)
  } finally {
    process.chdir(originalWorkingDirectory)
  }

  const existingAliases = coreConfig.resolve?.alias ?? {}
  const aliases = Array.isArray(existingAliases)
    ? existingAliases.filter(alias => alias.find !== '@')
    : Object.entries(existingAliases)
      .filter(([find]) => find !== '@')
      .map(([find, replacement]) => ({ find, replacement }))

  return {
    ...coreConfig,
    root: coreRoot,
    envDir: integrationDir,
    resolve: {
      ...coreConfig.resolve,
      alias: [
        { find: /^@\/i18n$/, replacement: path.join(integrationDir, 'i18n.js') },
        { find: /^@\/config$/, replacement: injectedConfig },
        ...aliases,
        { find: '@', replacement: coreSource }
      ]
    },
    server: {
      ...coreConfig.server,
      open: false,
      fs: {
        ...coreConfig.server?.fs,
        allow: [coreRoot, integrationDir, businessAdmin]
      }
    },
    build: {
      ...coreConfig.build,
      outDir: path.join(repositoryRoot, 'dist/admin'),
      emptyOutDir: true
    }
  }
}
