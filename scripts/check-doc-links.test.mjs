import assert from 'node:assert/strict'
import { execFileSync, spawnSync } from 'node:child_process'
import { copyFileSync, mkdtempSync, mkdirSync, rmSync, unlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'
import { checkMarkdownLinks, documentationFiles, markdownTargets } from './check-doc-links.mjs'

function fixture(t) {
  const root = mkdtempSync(join(tmpdir(), 'ns-doc-links-'))
  t.after(() => rmSync(root, { recursive: true, force: true }))
  mkdirSync(join(root, 'docs'))
  return root
}

test('checks files, directories, root paths, encoded names, images and reference definitions', t => {
  const root = fixture(t)
  writeFileSync(join(root, 'target (one).md'), '')
  writeFileSync(join(root, 'docs', 'guide.md'), [
    '[file](../target%20%28one%29.md#heading)',
    '[root](/target%20%28one%29.md?view=1#heading)',
    '[angle](<../target (one).md>)',
    '[balanced](../target\\ (one).md)',
    '[directory](../docs/)',
    '![image](missing.png "Caption")',
    '[reference][missing]',
    '[missing]: absent.md "Title"'
  ].join('\n'))
  assert.deepEqual(checkMarkdownLinks(root, ['docs/guide.md']), [
    { file: 'docs/guide.md', line: 6, target: 'missing.png', reason: 'target does not exist' },
    { file: 'docs/guide.md', line: 8, target: 'absent.md', reason: 'target does not exist' }
  ])
})

test('ignores code examples, external schemes, anchors and query-only links', t => {
  const root = fixture(t)
  const markdown = [
    '```md', '[example](absent.md)', '```',
    '~~~~', '[example](absent.md)', '~~~~',
    '`[inline](absent.md)`',
    '[web](https://example.test/a)', '[mail](mailto:docs@example.test)',
    '[web](//example.test/a)', '[anchor](#section)', '[query](?view=1)'
  ].join('\n')
  assert.equal(markdownTargets(markdown).length, 5)
  writeFileSync(join(root, 'README.md'), markdown)
  assert.deepEqual(checkMarkdownLinks(root, ['README.md']), [])
})

test('rejects repository escapes and malformed encoding with source line numbers', t => {
  const root = fixture(t)
  writeFileSync(join(root, 'docs', 'guide.md'), '# Guide\n\n[outside](../../outside.md)\n[invalid](bad%ZZ.md)')
  assert.deepEqual(checkMarkdownLinks(root, ['docs/guide.md']).map(({ line, reason }) => ({ line, reason })), [
    { line: 3, reason: 'target escapes the repository' },
    { line: 4, reason: 'invalid URL encoding' }
  ])
})

test('discovers tracked and new project docs, excludes ignored files and core sources, but checks links into core', t => {
  const root = fixture(t)
  execFileSync('git', ['init', '--quiet'], { cwd: root })
  mkdirSync(join(root, 'core'))
  writeFileSync(join(root, '.gitignore'), 'ignored.md\n')
  writeFileSync(join(root, 'ignored.md'), '[bad](absent)')
  writeFileSync(join(root, 'core', 'README.md'), '[upstream](absent)')
  writeFileSync(join(root, 'README.md'), '[core](core/README.md)')
  writeFileSync(join(root, 'deleted.md'), '')
  execFileSync('git', ['add', 'README.md', 'deleted.md'], { cwd: root })
  unlinkSync(join(root, 'deleted.md'))
  writeFileSync(join(root, 'docs', 'new.md'), '[root](../README.md)')
  assert.deepEqual(documentationFiles(root), ['README.md', 'docs/new.md'])
  assert.deepEqual(checkMarkdownLinks(root, documentationFiles(root)), [])
})

test('CLI fails with diagnostics for broken targets and passes after repair', t => {
  const root = fixture(t)
  execFileSync('git', ['init', '--quiet'], { cwd: root })
  mkdirSync(join(root, 'scripts'))
  const script = join(root, 'scripts', 'check-doc-links.mjs')
  copyFileSync(new URL('./check-doc-links.mjs', import.meta.url), script)
  writeFileSync(join(root, 'README.md'), '[guide](docs/guide.md)')
  const failed = spawnSync(process.execPath, [script], { cwd: root, encoding: 'utf8' })
  assert.equal(failed.status, 1)
  assert.match(failed.stderr, /README.md:1: target does not exist: docs\/guide.md/)
  writeFileSync(join(root, 'docs', 'guide.md'), '# Guide')
  const passed = spawnSync(process.execPath, [script], { cwd: root, encoding: 'utf8' })
  assert.equal(passed.status, 0, passed.stderr)
  assert.match(passed.stdout, /0 broken local targets/)
})
