/**
 * CLI wrapper for the trusted admin-agent manifest export.
 *
 * Kept separate from export-agent-manifest.mjs so Vite config loading can
 * import the library without executing its CLI block recursively.
 */
import path from 'node:path'

import { generateAgentManifest } from './export-agent-manifest.mjs'

const outFile = process.argv[2]
  ? path.resolve(process.argv[2])
  : undefined

try {
  const result = await generateAgentManifest(outFile ? { outFile } : {})
  process.stdout.write(
    `Wrote admin agent manifest: ${result.outFile} `
    + `(revision ${result.manifest.revision}, ${result.manifest.entities.length} entities)\n`
  )
} catch (error) {
  process.stderr.write(`Admin agent manifest export failed: ${error?.message || error}\n`)
  process.exitCode = 1
}
