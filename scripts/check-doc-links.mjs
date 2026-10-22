import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, realpathSync } from 'node:fs'
import { dirname, isAbsolute, relative, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

// Preserve line positions while excluding Markdown examples, not their contents' links.
function prose(markdown) {
  let fence = null
  return markdown.split('\n').map(line => {
    const marker = line.match(/^ {0,3}(`{3,}|~{3,})(.*)$/)
    if (fence) {
      if (marker && marker[1][0] === fence[0] && marker[1].length >= fence.length && !marker[2].trim()) fence = null
      return ''
    }
    if (marker) { fence = marker[1]; return '' }
    return line.replace(/(`+)[\s\S]*?\1/g, match => ' '.repeat(match.length))
  }).join('\n')
}

export function markdownTargets(markdown) {
  const text = prose(markdown)
  const targets = []
  const add = (target, index) => targets.push({ target, line: text.slice(0, index).split('\n').length })
  // Inline destinations support angle brackets, escaped characters and balanced parentheses.
  for (const match of text.matchAll(/!?\[[^\]\n]*\]\(\s*/g)) {
    let cursor = match.index + match[0].length
    let target = ''
    if (text[cursor] === '<') {
      const end = text.indexOf('>', cursor + 1)
      if (end < 0) continue
      target = text.slice(cursor + 1, end)
    } else {
      let depth = 0
      while (cursor < text.length) {
        const character = text[cursor++]
        if (character === '\\' && cursor < text.length) { target += character + text[cursor++]; continue }
        if ((character === ')' && depth === 0) || /\s/.test(character)) break
        if (character === '(') depth += 1
        if (character === ')') depth -= 1
        target += character
      }
    }
    if (target) add(target, match.index)
  }
  for (const match of text.matchAll(/^ {0,3}\[[^\]\n]+\]:\s*(?:<([^>\n]+)>|(\S+))/gm)) {
    add(match[1] ?? match[2], match.index)
  }
  return targets
}

export function checkMarkdownLinks(root, files) {
  root = resolve(root)
  const failures = []
  for (const file of files) {
    const source = resolve(root, file)
    for (const { target, line } of markdownTargets(readFileSync(source, 'utf8'))) {
      if (/^(?:[a-z][a-z\d+.-]*:|\/\/|#|\?)/i.test(target)) continue
      const pathname = target.split(/[?#]/, 1)[0]
      let destination
      try {
        const decoded = decodeURIComponent(pathname.replace(/\\([\\()[\] ])/g, '$1'))
        destination = decoded.startsWith('/') ? resolve(root, `.${decoded}`) : resolve(dirname(source), decoded)
      } catch {
        failures.push({ file, line, target, reason: 'invalid URL encoding' })
        continue
      }
      const local = relative(root, destination)
      const reason = local === '..' || local.startsWith('../') || isAbsolute(local)
        ? 'target escapes the repository' : !existsSync(destination) ? 'target does not exist' : null
      if (reason) failures.push({ file, line, target, reason })
    }
  }
  return failures
}

export function documentationFiles(root) {
  return [...new Set(execFileSync('git', ['ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', '*.md'], {
    cwd: root, encoding: 'utf8'
  }).split('\0').filter(file => file && !file.startsWith('core/') && existsSync(resolve(root, file))))].sort()
}

if (process.argv[1] && import.meta.url === pathToFileURL(realpathSync(process.argv[1])).href) {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const files = documentationFiles(root)
  const failures = checkMarkdownLinks(root, files)
  for (const failure of failures) console.error(`${failure.file}:${failure.line}: ${failure.reason}: ${failure.target}`)
  console.log(`Checked ${files.length} project Markdown files; ${failures.length} broken local targets.`)
  if (failures.length) process.exitCode = 1
}
