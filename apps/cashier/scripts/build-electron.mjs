import { build } from 'esbuild'

await Promise.all([
  build({ entryPoints: ['electron/main.ts'], outfile: 'dist-electron/main.mjs', bundle: true, platform: 'node', format: 'esm', external: ['electron'], target: 'node22' }),
  build({ entryPoints: ['electron/preload.ts'], outfile: 'dist-electron/preload.cjs', bundle: true, platform: 'node', format: 'cjs', external: ['electron'], target: 'node22' }),
])
