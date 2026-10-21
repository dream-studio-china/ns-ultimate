import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const VIRTUAL_ID = '\0ns-ultimate-agent-config'
const integrationDir = path.dirname(fileURLToPath(import.meta.url))

export function adminAgentPlugin(entryFile) {
  const resolvedEntry = path.resolve(entryFile)

  return {
    name: 'ns-ultimate-admin-agent',
    enforce: 'pre',
    resolveId(id) {
      return id === 'virtual:ns-ultimate-agent-config' ? VIRTUAL_ID : null
    },
    load(id) {
      if (id !== VIRTUAL_ID) return null
      return `export const enabled = import.meta.env.VITE_ADMIN_AGENT_ENABLED !== 'false'`
    },
    transformIndexHtml: {
      order: 'pre',
      handler(html) {
        if (!html.includes('<div id="app"></div>')) {
          this.warn('Admin agent loader was not added: expected the admin mount element was not found.')
          return html
        }

        const sessionAdapter = path.join(integrationDir, 'agent-session.js')
        const hostAdapter = path.join(integrationDir, 'agent-host.js')
        const source = `import { enabled } from 'virtual:ns-ultimate-agent-config';\nif (enabled) import(${JSON.stringify(sessionAdapter)}).then(({ monitorAdminSession }) => monitorAdminSession(async () => { const [{ mountAdminAgent }, { createAdminAgentHost }] = await Promise.all([import(${JSON.stringify(resolvedEntry)}), import(${JSON.stringify(hostAdapter)})]); return mountAdminAgent(createAdminAgentHost()); }, () => {})).catch(error => console.warn('[admin agent] Failed to initialize.', error));`
        return html.replace('</body>', `<script type="module">${source}</script>\n</body>`)
      }
    },
    configureServer(server) {
      server.watcher.add(path.dirname(resolvedEntry))
    },
    buildStart() {
      if (!fs.existsSync(resolvedEntry)) {
        this.error(`Admin agent entry does not exist: ${resolvedEntry}`)
      }
    }
  }
}
