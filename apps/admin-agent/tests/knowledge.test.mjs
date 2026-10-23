import assert from 'node:assert/strict'
import test from 'node:test'
import {
  MAX_CONTEXT_CHARACTERS,
  MAX_EXCERPT_CHARACTERS,
  MAX_SELECTED_ARTICLES,
  buildDocumentQuery,
  chunkArticle,
  excerptArticle,
  loadKnowledgeArticles,
  normalizeKnowledgeArticle,
  normalizeKnowledgeArticles,
  selectKnowledgeContext
} from '../src/knowledge.js'
import { normalizeOpenApi, searchOpenApi } from '../src/openapi.js'
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
  assert.deepEqual(selectKnowledgeContext('nope', 'product'), { selected: [], references: [], contextText: '', searchTerms: [] })
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

test('excerpts long content around the first match and cites only safe metadata', () => {  const long = article({ content: `${'x '.repeat(3000)}product filters are described here${'y '.repeat(3000)}` })
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

test('chunks long articles at paragraph and section boundaries', () => {
  const paragraph = (label) => `${label}: ` + 'detail sentence. '.repeat(40)
  const articleWithSections = article({
    content: ['## Setup', paragraph('Account setup'), '', '## Printing', paragraph('Print job handling reprint rules')].join('\n')
  })
  const chunks = chunkArticle(articleWithSections)
  assert.ok(chunks.length >= 2)
  assert.ok(chunks.some(chunk => chunk.section === 'Setup'))
  assert.ok(chunks.some(chunk => chunk.section === 'Printing'))
  for (const chunk of chunks) assert.ok(chunk.text.length <= 900)

  const excerpt = excerptArticle(articleWithSections, ['reprint'], [], 'reprint')
  assert.ok(excerpt.startsWith('Printing'))
  assert.ok(excerpt.includes('reprint'))
  assert.ok(!excerpt.includes('Account setup'))
  assert.ok(excerpt.trimEnd().endsWith('.') || excerpt.trimEnd().endsWith('。'), 'excerpt ends at a sentence boundary')

  const long = article({
    content: [paragraph('Introduction'), paragraph('Unrelated filters'), paragraph('Printing reprint rules')].join('\n')
  })
  const selected = excerptArticle(long, ['reprint'], [], 'reprint')
  assert.ok(selected.includes('reprint'))
  assert.ok(!selected.includes('Introduction'))
})

test('derives bounded English search terms from matched knowledge aliases', () => {
  const articles = [article({ aliases: ['printer', '打印机', 'Xpyun', 'printer device'] })]
  const result = selectKnowledgeContext(articles, '打印机怎么接入？')
  assert.equal(result.selected.length, 1)
  assert.deepEqual(result.searchTerms.sort(), ['device', 'printer', 'xpyun'])
  assert.equal(buildDocumentQuery('打印机怎么接入？', result.searchTerms).startsWith('打印机怎么接入？ '), true)
  assert.ok(buildDocumentQuery('q', ['printer', 'printer', '打印机', 'x']).split(' ').length <= 3)
})

test('Chinese questions reach English API documentation through host aliases', () => {
  const operations = normalizeOpenApi({
    paths: {
      '/api/v1/manage/printer/devices': {
        get: { operationId: 'printer-devices-list', summary: 'List printer devices', tags: ['Printer'] }
      },
      '/api/v1/manage/contents': {
        get: { operationId: 'contents-list', summary: 'List content records', tags: ['Content'] }
      }
    }
  })
  assert.deepEqual(searchOpenApi(operations, '打印机怎么接入？'), [])
  const knowledge = selectKnowledgeContext([article({ aliases: ['printer', '打印机'] })], '打印机怎么接入？')
  const matched = searchOpenApi(operations, buildDocumentQuery('打印机怎么接入？', knowledge.searchTerms))
  assert.deepEqual(matched.map(operation => operation.path), ['/api/v1/manage/printer/devices'])
})

test('knowledge never widens model tools, navigation, or read catalog surface', () => {
  const names = createAssistantTools([]).map(tool => tool.function.name)
  assert.deepEqual(names, ['list_entities'])
  assert.ok(!names.some(name => /knowledge/i.test(name)))
})
