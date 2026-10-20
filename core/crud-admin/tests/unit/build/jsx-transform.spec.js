// @vitest-environment node
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import viteConfig from '../../../vite.config'
import vitestConfig from '../../../vitest.config'

const root = process.cwd()

describe.each([
  ['build', viteConfig({ command: 'build', mode: 'production' })],
  ['test', vitestConfig]
])('%s JSX transform boundaries', (_name, config) => {
  async function transform(code, filename) {
    const plugin = config.plugins.find(plugin => plugin.name === 'vite:vue-jsx')
    plugin.configResolved({
      command: 'build',
      isProduction: true,
      root,
      build: { sourcemap: false }
    })
    const handler = typeof plugin.transform === 'function' ? plugin.transform : plugin.transform.handler
    return handler.call({}, code, path.resolve(root, filename))
  }

  it.each(['src/example.js', 'src/example.ts', 'node_modules/example/index.js', 'node_modules/example/index.jsx'])(
    'does not run Babel on %s', async filename => {
      expect(await transform('export const value = 1', filename)).toBeUndefined()
    }
  )

  it.each(['src/example.jsx', 'src/example.tsx', '../../business/admin/example.jsx'])(
    'compiles application JSX in %s', async filename => {
      const result = await transform('export const render = () => <div />', filename)
      expect(result.code).toContain('createVNode')
      expect(result.code).not.toContain('<div')
    }
  )
})
