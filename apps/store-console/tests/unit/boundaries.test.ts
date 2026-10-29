import { readFileSync, readdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { en } from '../../src/locales/en'
import { zhCN } from '../../src/locales/zh-CN'

describe('application boundaries', () => {
  it('keeps translation keys aligned', () => expect(Object.keys(en).sort()).toEqual(Object.keys(zhCN).sort()))
  it('keeps payment imports independent of staff code', () => {
    const visited = new Set<string>()
    const visit = (file: string) => {
      if (visited.has(file)) return
      visited.add(file)
      expect(file).not.toContain('/src/staff/')
      const content = readFileSync(file, 'utf8')
      for (const match of content.matchAll(/from\s+['"]([.][^'"]+)['"]/g)) {
        const stem = resolve(file, '..', match[1])
        const name = readdirSync(resolve(stem, '..')).find((name) => [`${stem}.ts`, `${stem}.tsx`].includes(resolve(stem, '..', name)))
        if (name) visit(resolve(stem, '..', name))
      }
    }
    visit(resolve('src/payment/main.tsx'))
  })
})
