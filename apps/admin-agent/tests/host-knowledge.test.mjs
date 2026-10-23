import assert from 'node:assert/strict'
import { existsSync } from 'node:fs'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import { knowledgeArticles } from '../adapters/ns-ultimate/admin/knowledge/index.mjs'
import { normalizeKnowledgeArticles, selectKnowledgeContext } from '../src/knowledge.js'

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')

test('host knowledge articles are unique, bounded, valid, and cite repository sources', () => {
  const ids = knowledgeArticles.map(article => article.id)
  assert.equal(new Set(ids).size, ids.length)
  assert.equal(normalizeKnowledgeArticles(knowledgeArticles).length, knowledgeArticles.length)
  for (const article of knowledgeArticles) {
    assert.ok(article.content.length <= 20000, article.id)
    assert.ok(article.sourcePath, article.id)
    assert.ok(existsSync(path.join(repositoryRoot, article.sourcePath)), article.sourcePath)
  }
})

test('host knowledge retrieval covers independent modules in English and Chinese', () => {
  const cases = [
    ['how to reprint a printer job', 'ns-printer-setup'],
    ['微信支付配置', 'ns-wechat-setup'],
    ['门店商品的厨房打印设置', 'ns-store-product'],
    ['Counter store binding', 'ns-counter-guide']
  ]
  for (const [question, expectedId] of cases) {
    const result = selectKnowledgeContext(knowledgeArticles, question)
    assert.ok(result.selected.some(article => article.id === expectedId), question)
    assert.ok(result.references.some(reference => reference.sourcePath), question)
  }
})
