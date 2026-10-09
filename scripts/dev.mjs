import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../', import.meta.url))
const children = []
let stopping = false

function stop(code) {
  if (stopping) return
  stopping = true
  for (const child of children) {
    if (!child.pid) continue
    try {
      // Each service has its own process group, including npm's child processes.
      process.kill(-child.pid, 'SIGTERM')
    } catch (error) {
      if (error.code !== 'ESRCH') console.error(error.message)
    }
  }
  process.exitCode = code
}

for (const service of ['backend', 'admin']) {
  const child = spawn('bash', ['scripts/project.sh', service, ...process.argv.slice(2)], {
    cwd: root,
    stdio: 'inherit',
    detached: true
  })
  children.push(child)
  child.on('error', error => {
    console.error(`${service}: ${error.message}`)
    stop(1)
  })
  child.on('exit', (code, signal) => {
    if (!stopping) {
      console.error(`${service} stopped (${signal ?? code}); stopping both services.`)
      stop(code || 1)
    }
  })
}
process.on('SIGINT', () => stop(130))
process.on('SIGTERM', () => stop(143))
