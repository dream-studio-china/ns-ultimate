// @vitest-environment node
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { build } from 'vite'
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

  it.each(['node_modules/example/index.js', 'node_modules/example/index.ts', 'node_modules/example/index.jsx', 'node_modules/example/index.tsx'])(
    'does not run Babel on %s', async filename => {
      expect(await transform('export const value = 1', filename)).toBeUndefined()
    }
  )

  it.each(['src/example.js', 'src/example.ts', 'src/example.jsx', 'src/example.tsx', '../../business/admin/example.js', '../../business/admin/example.ts'])(
    'compiles application JSX in %s', async filename => {
      const result = await transform('export const render = () => <div />', filename)
      expect(result.code).toContain('createVNode')
      expect(result.code).not.toContain('<div')
    }
  )

  it.each(['js', 'ts', 'jsx', 'tsx'])('compiles JSX in .%s files through the complete Vite pipeline', async extension => {
    const filename = path.resolve(root, `src/jsx-compatibility.${extension}`)
    const result = await build({
      configFile: false,
      plugins: [...config.plugins, {
        name: 'jsx-compatibility-fixture',
        resolveId(id) { if (id === filename) return filename },
        load(id) {
          if (id === filename) return extension.startsWith('ts')
            ? 'interface Props { label: string }; export const render = (props: Props) => <div>{props.label}</div>'
            : 'export const render = props => <div>{props.label}</div>'
        }
      }],
      logLevel: 'silent',
      build: {
        write: false,
        minify: false,
        lib: { entry: filename, formats: ['es'] },
        rollupOptions: { external: ['vue'] }
      }
    })
    const code = result[0].output.find(chunk => chunk.type === 'chunk').code
    expect(code).toContain('createVNode')
    expect(code).not.toContain('interface Props')
    expect(code).not.toContain('<div>')
  })
})
