/**
 * Vite hook for the trusted admin-agent manifest.
 *
 * Development: generate once, then invalidate immediately on relevant source
 * changes and regenerate from a queue. The shared publication controller
 * serializes publish/revoke state so an older writer cannot republish after a
 * revocation or delete a newer artifact.
 *
 * Build: generate before the production build and fail the build on any error.
 */
import path from 'node:path'
import { fileURLToPath } from 'node:url'

import {
  createAgentManifestPublication,
  DEFAULT_MANIFEST_PATH,
  generateAgentManifest
} from './export-agent-manifest.mjs'

export const AGENT_MANIFEST_PLUGIN_NAME = 'ns-ultimate-agent-manifest'

const integrationDir = path.dirname(fileURLToPath(import.meta.url))
const repositoryRoot = path.resolve(integrationDir, '../..')
const watchedDirectories = [
  integrationDir,
  path.join(repositoryRoot, 'business/admin'),
  path.join(repositoryRoot, 'core/crud-admin/src/configs'),
  path.join(repositoryRoot, 'core/crud-admin/src/router'),
  path.join(repositoryRoot, 'core/crud-admin/src/i18n'),
  path.join(repositoryRoot, 'core/crud-admin/src/utils'),
  path.join(repositoryRoot, 'apps/admin-agent/src'),
  path.join(repositoryRoot, 'apps/admin-agent/adapters/ns-ultimate/admin')
]

function isRelevant(file) {
  if (typeof file !== 'string' || !file) return false
  const resolved = path.resolve(file)
  return watchedDirectories.some(directory => resolved === directory || resolved.startsWith(`${directory}${path.sep}`))
}

function errorMessage(error) {
  return error instanceof Error ? error.message : String(error)
}

export function agentManifestPlugin({ outFile = DEFAULT_MANIFEST_PATH } = {}) {
  let timer = null
  let queue = Promise.resolve()
  let closed = false
  const publication = createAgentManifestPublication({ outFile })

  const enqueue = task => {
    queue = queue.then(task, task)
    return queue
  }

  const revoke = async logger => {
    try {
      await publication.invalidate()
    } catch (error) {
      logger?.error(`[admin-manifest] Could not revoke ${outFile}: ${errorMessage(error)}`)
    }
  }

  const generate = async ({ mode, command, logger }) => {
    if (closed) return
    await revoke(logger)
    try {
      await generateAgentManifest({ outFile, mode, command, publication })
    } catch (error) {
      if (!closed && !/superseded/i.test(errorMessage(error))) {
        logger?.error(`[admin-manifest] Export failed: ${errorMessage(error)}`)
      }
      throw error
    }
  }

  return {
    name: AGENT_MANIFEST_PLUGIN_NAME,

    async configureServer(server) {
      const logger = server.config.logger
      await enqueue(() => generate({
        mode: server.config.mode,
        command: 'serve',
        logger
      }).catch(() => {}))

      for (const directory of watchedDirectories) {
        try {
          server.watcher.add(directory)
        } catch (error) {
          logger.error(`[admin-manifest] Could not watch ${directory}: ${errorMessage(error)}`)
        }
      }

      const onChange = () => {
        if (closed) return
        // Invalidate synchronously, remove the published artifact immediately,
        // and let any in-flight writer fail its post-rename freshness check.
        void revoke(logger)
        if (timer) clearTimeout(timer)
        timer = setTimeout(() => {
          timer = null
          void enqueue(() => generate({
            mode: server.config.mode,
            command: 'serve',
            logger
          }).catch(() => {}))
        }, 150)
      }

      const onWatcher = (event, file) => {
        if (event === 'add' || event === 'change' || event === 'unlink') {
          if (isRelevant(file)) onChange()
        }
      }

      server.watcher.on('all', onWatcher)
      server.httpServer?.once('close', () => {
        closed = true
        if (timer) clearTimeout(timer)
        timer = null
        server.watcher.off?.('all', onWatcher)
        void publication.invalidate()
      })
    },

    async buildStart() {
      await revoke(this.environment?.logger ?? this.logger)
      try {
        await generateAgentManifest({ outFile, mode: 'production', command: 'build', publication })
      } catch (error) {
        await revoke(this.environment?.logger ?? this.logger)
        this.error(`Admin agent manifest export failed: ${errorMessage(error)}`)
      }
    }
  }
}

export default agentManifestPlugin
