import assert from 'node:assert/strict'
import test from 'node:test'
import {
  MAX_CONTEXT_CHARACTERS,
  MAX_EXCERPT_CHARACTERS,
  MAX_SELECTED_ARTICLES,
  excerptArticle,
  loadKnowledgeArticles,
  normalizeKnowledgeArticle,
  normalizeKnowledgeArticles,
  selectKnowledgeContext
} from '../src/knowledge.js'
import { createAssistantTools } from '../src/tools.js'

const article = (overrides = {}) => ({
  id: 'a1',
  title: 'Product list filters',
  module: 'Product',
  kind: 'guide',
  locale: 'en',
  aliases: ['product', '筛选'],
  sourcePath: 'docs/product.md',
  content: 'The product list API supports name and status filters.',
  ...overrides
})

test('fails closed when the host omits, throws, or returns invalid knowledge', async() => {
  assert.deepEqual(await loadKnowledgeArticles({}), [])
  assert.deepEqual(await loadKnowledgeArticles({ getKnowledgeArticles: () => { throw new Error('boom') } }), [])
  assert.deepEqual(await loadKnowledgeArticles({ getKnowledgeArticles: () => 'nope' }), [])
  assert.deepEqual(await loadKnowledgeArticles({ getKnowledgeArticles: () => [{ id: '', title: '', content: '' }] }), [])
  assert.deepEqual(selectKnowledgeContext('nope', 'product'), { selected: [], references: [], contextText: '' })
})

test('validates bounds, truncates overlong fields, and de-duplicates by id', () => {
  assert.equal(normalizeKnowledgeArticle(null), null)
  assert.equal(normalizeKnowledgeArticle({ id: 'x', title: 't' }), null)
  const normalized = normalizeKnowledgeArticles([
    article({ id: 'dup', title: 'First', content: 'product here' }),
    article({ id: 'dup', title: 'Second', content: 'product again' }),
    article({ id: 'x'.repeat(500), title: 't'.repeat(500), content: 'product', aliases: ['ok', 42, 'x'], sourcePath: 's'.repeat(900) })
  ])
  assert.equal(normalized.length, 2)
  assert.equal(normalized[0].title, 'First')
  assert.ok(normalized[1].id.length <= 120)
  assert.ok(normalized[1].title.length <= 200)
  assert.ok(normalized[1].sourcePath.length <= 500)
  assert.deepEqual(normalized[1].aliases, ['ok'])
  const many = Array.from({ length: 250 }, (_, index) => article({ id: `id-${index}`, content: `product ${index}` }))
  assert.equal(normalizeKnowledgeArticles(many).length, 200)
})

test('matches English and Chinese terms, ranks aliases first, and bounds selection', () => {
  const articles = [
    article({ id: 'content-only', title: 'Unrelated title', aliases: [], content: 'mentions product deep in the body text' }),
    article({ id: 'alias-hit', title: 'Other', aliases: ['product'], content: 'nothing relevant here' }),
    article({ id: 'title-hit', title: 'Product filters guide', aliases: [], content: 'nothing relevant here' })
  ]
  const result = selectKnowledgeContext(articles, 'how do product filters work?')
  assert.ok(result.selected.length >= 2)
  assert.equal(result.selected[0].id, 'alias-hit')
  const chinese = selectKnowledgeContext(
    [article({ id: 'zh', title: '商品查询', aliases: ['筛选'], content: '商品列表支持按名称筛选商品商品查询' })],
    '商品查询接口支持哪些筛选？'
  )
  assert.equal(chinese.selected.length, 1)
  assert.equal(chinese.selected[0].id, 'zh')
  const crowded = Array.from({ length: 10 }, (_, index) => article({ id: `k-${index}`, content: `product content ${index}` }))
  const bounded = selectKnowledgeContext(crowded, 'product')
  assert.ok(bounded.selected.length <= MAX_SELECTED_ARTICLES)
  assert.ok(bounded.contextText.length <= MAX_CONTEXT_CHARACTERS)
  for (const item of bounded.selected) assert.ok(item.excerpt.length <= MAX_EXCERPT_CHARACTERS + 2)
  const sharedBudget = selectKnowledgeContext(crowded, 'product', { maxContextCharacters: 350 })
  assert.ok(sharedBudget.contextText.length <= 350)
  assert.equal(selectKnowledgeContext(crowded, 'product', { maxContextCharacters: 0 }).selected.length, 0)
  assert.deepEqual(selectKnowledgeContext(articles, 'xylophone quasar zebra').selected, [])
  assert.deepEqual(selectKnowledgeContext(articles, '').selected, [])
})

test('excerpts long content around the first match and cites only safe metadata', () => {
  const long = article({ content: `${'x '.repeat(3000)}product filters are described here${'y '.repeat(3000)}` })
  const excerpt = excerptArticle(long, ['product'], [])
  assert.ok(excerpt.includes('product filters are described here'))
  assert.ok(excerpt.length <= MAX_EXCERPT_CHARACTERS + 2)
  const result = selectKnowledgeContext([long], 'product filters')
  assert.equal(result.references.length, 1)
  assert.deepEqual(Object.keys(result.references[0]).sort(), ['id', 'kind', 'module', 'sourcePath', 'title'])
  assert.ok(!result.contextText.includes('docs/product.md'))
  assert.equal(result.references[0].sourcePath, 'docs/product.md')
  assert.equal(normalizeKnowledgeArticle(article({ sourcePath: 'https://example.invalid/source' })).sourcePath, '')
  assert.equal(normalizeKnowledgeArticle(article({ sourcePath: '../outside.md' })).sourcePath, '')
})

test('knowledge never widens model tools, navigation, or read catalog surface', () => {
  const names = createAssistantTools([]).map(tool => tool.function.name)
  assert.deepEqual(names, ['list_entities'])
  assert.ok(!names.some(name => /knowledge/i.test(name)))
})
