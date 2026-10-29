import { spawn } from 'node:child_process'
import { createServer } from 'vite'
import electron from 'electron'

await import('./build-electron.mjs')
const server = await createServer()
await server.listen()
server.printUrls()
const env = { ...process.env, CASHIER_DEV_URL: 'http://127.0.0.1:5178' }
delete env.ELECTRON_RUN_AS_NODE
const child = spawn(electron, ['.'], { env, stdio: 'inherit' })
const stop = () => { child.kill(); void server.close() }
process.on('SIGINT', stop)
process.on('SIGTERM', stop)
child.on('error', (error) => { console.error(error); stop(); process.exitCode = 1 })
child.on('exit', async (code) => { await server.close(); process.exit(code ?? 0) })
