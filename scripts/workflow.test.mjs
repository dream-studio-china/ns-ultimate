import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, unlinkSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import test from 'node:test'

const root = fileURLToPath(new URL('../', import.meta.url))

function fixture(t) {
  const directory = mkdtempSync(path.join(tmpdir(), 'ns-ultimate-env-'))
  t.after(() => rmSync(directory, { recursive: true, force: true }))
  for (const subdirectory of ['scripts', 'integration/admin', 'integration/backend']) {
    mkdirSync(path.join(directory, subdirectory), { recursive: true })
  }
  for (const file of ['scripts/env.php', 'integration/admin/.env.local.example']) {
    copyFileSync(path.join(root, file), path.join(directory, file))
  }
  return directory
}

function run(directory, command) {
  return spawnSync('php', [path.join(directory, 'scripts/env.php'), command], { encoding: 'utf8' })
}

test('env initialization generates private local files and preserves them on repeat', t => {
  const directory = fixture(t)
  const first = run(directory, 'env-init')
  assert.equal(first.status, 0, first.stderr)
  const files = ['integration/admin/.env.local', 'integration/backend/.env.local', 'var/keys/private.pem', 'var/keys/public.pem']
  const contents = files.map(file => readFileSync(path.join(directory, file), 'utf8'))
  assert.match(contents[1], /APP_SECRET=[a-f0-9]{64}/)
  assert.match(contents[2], /BEGIN PRIVATE KEY/)
  assert.equal(statSync(path.join(directory, files[1])).mode & 0o777, 0o600)
  const second = run(directory, 'env-init')
  assert.equal(second.status, 0, second.stderr)
  files.forEach((file, index) => assert.equal(readFileSync(path.join(directory, file), 'utf8'), contents[index]))
  assert.ok(!first.stdout.includes(contents[1].split('APP_SECRET=')[1].split('\n')[0]))
})

test('incomplete key pairs fail without replacing the existing key', t => {
  const directory = fixture(t)
  assert.equal(run(directory, 'env-init').status, 0)
  const privatePath = path.join(directory, 'var/keys/private.pem')
  const original = readFileSync(privatePath, 'utf8')
  unlinkSync(path.join(directory, 'var/keys/public.pem'))
  const result = run(directory, 'env-init')
  assert.notEqual(result.status, 0)
  assert.match(result.stderr, /Incomplete JWT key pair/)
  assert.equal(readFileSync(privatePath, 'utf8'), original)
})

test('env check reports missing local files without requiring dependencies', t => {
  const result = run(fixture(t), 'env-check')
  assert.equal(result.status, 1)
  assert.match(result.stdout, /Missing: integration\/backend\/\.env.local/)
})

test('backend test environment resolves repository paths and ignores development secrets', () => {
  const environment = { ...process.env, APP_ENV: 'test' }
  for (const name of ['APP_SECRET', 'REFRESH_TOKEN_SECRET', 'JWT_PRIVATE_KEY_PATH', 'JWT_PUBLIC_KEY_PATH', 'SYMFONY_DOTENV_VARS']) delete environment[name]
  const result = spawnSync('php', ['-r', `
    require $argv[1];
    if ($_SERVER['APP_SECRET'] !== 'integration-test-only') exit(1);
    if ($_SERVER['JWT_PRIVATE_KEY_PATH'] !== $_SERVER['NS_PROJECT_ROOT'].'/core/crud-skeleton/tests/Identity/Security/test_private.pem') exit(2);
    if (!is_file($_SERVER['JWT_PRIVATE_KEY_PATH'])) exit(3);
  `, path.join(root, 'integration/backend/bootstrap.php')], { cwd: tmpdir(), env: environment, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr)
})

test('composed Vite config uses integration env files and respects process overrides', () => {
  const environment = { ...process.env }
  for (const name of Object.keys(environment)) {
    if (name.startsWith('VITE_')) delete environment[name]
  }
  environment.VITE_PROXY_TARGET = 'http://127.0.0.1:12345'
  environment.VITE_BASE_API = '/workflow-test'
  const result = spawnSync(process.execPath, ['--input-type=module', '-e', `
    import assert from 'node:assert/strict';
    import { createRequire } from 'node:module';
    const require = createRequire(process.argv[1] + '/core/crud-admin/package.json');
    const { loadConfigFromFile } = require('vite');
    const root = process.argv[1];
    for (const mode of ['development', 'production']) {
      const { config } = await loadConfigFromFile({ mode, command: 'build' }, root + '/integration/admin/vite.config.ts');
      assert.equal(config.envDir, root + '/integration/admin');
      assert.equal(config.define['process.env.VITE_BASE_API'], '"/workflow-test"');
      assert.equal(config.server.proxy['/api'].target, 'http://127.0.0.1:12345');
    }
  `, root.replace(/\/$/, '')], { env: environment, encoding: 'utf8' })
  assert.equal(result.status, 0, result.stderr)
})
