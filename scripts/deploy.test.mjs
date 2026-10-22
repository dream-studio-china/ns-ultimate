import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const deploy = readFileSync(new URL('./deploy.sh', import.meta.url), 'utf8')

// Exercise the actual backend deployment phase with harmless command doubles.
// Do not run the root-only deploy entry point or touch a database/filesystem.
function runBackendPhase({ mode = 'backend', failCommand = '' } = {}) {
  const phase = deploy.match(/if \[\[ "\$MODE" != frontend \]\]; then\n  cache_dir=[\s\S]*?\nfi/)
  assert.ok(phase, 'Backend deployment phase must be present')
  return spawnSync('bash', ['-c', `
set -Eeuo pipefail
ROOT=/isolated-deploy-fixture
MODE="$1"
FAIL_COMMAND="$2"
PHP_FPM_USER=fixture
PHP_FPM_GROUP=fixture
PHP_BIN=fake_php
install() { :; }
chown() { :; }
chmod() { :; }
run_console() {
  printf 'CONSOLE %s\\n' "$*"
  [[ "$*" != "$FAIL_COMMAND" ]] || return 23
}
fake_php() {
  [[ "$APP_ENV" == prod && "$APP_DEBUG" == 0 ]] || return 24
  [[ "$1" == "$ROOT/integration/backend/bin/console" ]] || return 25
  shift
  printf 'CONSOLE %s\\n' "$*"
}
${phase[0]}
`, 'deploy-test', mode, failCommand], { encoding: 'utf8' })
}

function commands(result) {
  return result.stdout.split('\n').filter(line => line.startsWith('CONSOLE ')).map(line => line.slice(8))
}

for (const mode of ['backend', 'all']) {
  test(`${mode} deployment refreshes container before migration discovery and warms after migration`, () => {
    const result = runBackendPhase({ mode })
    assert.equal(result.status, 0, result.stderr)
    assert.deepEqual(commands(result), [
      'cache:clear --no-warmup',
      'doctrine:migrations:status',
      'doctrine:migrations:migrate --no-interaction',
      'assets:install /isolated-deploy-fixture/integration/backend/public',
      'cache:clear'
    ])
  })
}

test('container refresh failure stops deployment before migration discovery', () => {
  const result = runBackendPhase({ failCommand: 'cache:clear --no-warmup' })
  assert.equal(result.status, 23)
  assert.deepEqual(commands(result), ['cache:clear --no-warmup'])
})

test('migration failure stops deployment before assets and final cache warmup', () => {
  const result = runBackendPhase({ failCommand: 'doctrine:migrations:migrate --no-interaction' })
  assert.equal(result.status, 23)
  assert.deepEqual(commands(result), ['cache:clear --no-warmup', 'doctrine:migrations:status', 'doctrine:migrations:migrate --no-interaction'])
})

test('frontend-only deployment does not touch backend cache or migrations', () => {
  const result = runBackendPhase({ mode: 'frontend' })
  assert.equal(result.status, 0, result.stderr)
  assert.deepEqual(commands(result), [])
})
