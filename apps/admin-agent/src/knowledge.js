export const MAX_KNOWLEDGE_SOURCE_ARTICLES = 200
export const MAX_SELECTED_ARTICLES = 4
export const MAX_EXCERPT_CHARACTERS = 2000
export const MAX_CONTEXT_CHARACTERS = 8000
export const MAX_CHUNK_CHARACTERS = 900
export const MAX_SEARCH_TERMS = 6

const MAX_ID_CHARACTERS = 120
const MAX_TITLE_CHARACTERS = 200
const MAX_MODULE_CHARACTERS = 120
const MAX_KIND_CHARACTERS = 60
const MAX_LOCALE_CHARACTERS = 20
const MAX_SOURCE_PATH_CHARACTERS = 500
const MAX_CONTENT_CHARACTERS = 20000
const MAX_ALIASES = 20
const MAX_ALIAS_CHARACTERS = 120
const MAX_QUERY_TOKENS = 20
const MAX_QUERY_BIGRAMS = 40
const MAX_SECTION_CHARACTERS = 120
const MAX_CHUNKS_PER_ARTICLE = 3

function cleanText(value, maxLength) {
  if (typeof value !== 'string') return ''
  return value.replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, maxLength)
}

function cleanSourcePath(value) {
  const path = cleanText(value, MAX_SOURCE_PATH_CHARACTERS)
  if (!path || path.startsWith('/') || path.includes('\\')
    || path.split('/').some(segment => segment === '..')
    || !/^[A-Za-z0-9._/-]+$/u.test(path)) return ''
  return path
}

export function normalizeKnowledgeArticle(raw) {
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return null
  const id = cleanText(raw.id, MAX_ID_CHARACTERS)
  const title = cleanText(raw.title, MAX_TITLE_CHARACTERS)
  const content = typeof raw.content === 'string'
    ? raw.content.replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/g, '').trim().slice(0, MAX_CONTENT_CHARACTERS)
    : ''
  if (!id || !title || !content) return null
  const aliases = Array.isArray(raw.aliases)
    ? [...new Set(raw.aliases
      .filter(alias => typeof alias === 'string')
      .map(alias => cleanText(alias, MAX_ALIAS_CHARACTERS))
      .filter(alias => alias.length >= 2)
      .slice(0, MAX_ALIASES))]
    : []
  return {
    id,
    title,
    module: cleanText(raw.module, MAX_MODULE_CHARACTERS),
    kind: cleanText(raw.kind, MAX_KIND_CHARACTERS),
    locale: cleanText(raw.locale, MAX_LOCALE_CHARACTERS),
    aliases,
    sourcePath: cleanSourcePath(raw.sourcePath),
    content
  }
}

export function normalizeKnowledgeArticles(value) {
  if (!Array.isArray(value)) return []
  const seen = new Set()
  const output = []
  for (const raw of value.slice(0, MAX_KNOWLEDGE_SOURCE_ARTICLES)) {
    const article = normalizeKnowledgeArticle(raw)
    if (!article || seen.has(article.id)) continue
    seen.add(article.id)
    output.push(article)
  }
  return output
}

export async function loadKnowledgeArticles(host) {
  try {
    if (!host || typeof host.getKnowledgeArticles !== 'function') return []
    const raw = await host.getKnowledgeArticles()
    return normalizeKnowledgeArticles(raw)
  } catch {
    return []
  }
}

function latinTokens(text) {
  const matches = String(text).toLocaleLowerCase().match(/[a-z0-9]+/gu) || []
  return [...new Set(matches.filter(token => token.length >= 2))].slice(0, MAX_QUERY_TOKENS)
}

function cjkBigrams(text) {
  const runs = String(text).toLocaleLowerCase().match(/[\u3400-\u4dbf\u4e00-\u9fff]+/gu) || []
  const bigrams = []
  const seen = new Set()
  for (const run of runs) {
    if (run.length < 2) continue
    for (let index = 0; index + 2 <= run.length && bigrams.length < MAX_QUERY_BIGRAMS; index += 1) {
      const bigram = run.slice(index, index + 2)
      if (seen.has(bigram)) continue
      seen.add(bigram)
      bigrams.push(bigram)
    }
    if (bigrams.length >= MAX_QUERY_BIGRAMS) break
  }
  return bigrams
}

function scoreText(text, aliasText, latin, bigrams, queryText, weight = 1) {
  const body = text.toLocaleLowerCase()
  let score = 0
  for (const alias of aliasText) {
    if (alias.length >= 2 && queryText.includes(alias)) score += 4 * weight
  }
  for (const token of latin) {
    if (body.includes(token)) score += 3 * weight
  }
  for (const bigram of bigrams) {
    if (body.includes(bigram)) score += 2 * weight
  }
  return score
}

function scoreArticle(article, latin, bigrams, queryText) {
  const title = article.title.toLocaleLowerCase()
  const aliasText = article.aliases.map(alias => alias.toLocaleLowerCase())
  let score = 0
  for (const alias of aliasText) {
    if (alias.length >= 2 && queryText.includes(alias)) score += 4
  }
  for (const token of latin) {
    if (title.includes(token)) score += 3
    else if (aliasText.some(alias => alias.includes(token))) score += 2
    else if (article.content.toLocaleLowerCase().includes(token)) score += 1
  }
  for (const bigram of bigrams) {
    if (title.includes(bigram)) score += 2
    else if (aliasText.some(alias => alias.includes(bigram))) score += 2
    else if (article.content.toLocaleLowerCase().includes(bigram)) score += 1
  }
  return score
}

/** Split a paragraph that exceeds one chunk at a sentence boundary when possible. */
function splitLongParagraph(text, limit) {
  const parts = []
  let rest = text
  while (rest.length > limit) {
    const window = rest.slice(0, limit)
    const candidates = [window.lastIndexOf('. '), window.lastIndexOf('。'), window.lastIndexOf('; '), window.lastIndexOf('；')]
      .filter(index => index >= Math.floor(limit * 0.5))
    const cut = candidates.length ? Math.max(...candidates) + 1 : limit
    parts.push(rest.slice(0, cut).trim())
    rest = rest.slice(cut).trim()
  }
  if (rest) parts.push(rest)
  return parts.filter(Boolean)
}

/**
 * Split article content into paragraph chunks. Markdown headings become section
 * labels so a selected chunk stays readable without copying the whole article.
 */
export function chunkArticle(article) {
  const blocks = []
  let section = ''
  for (const rawLine of article.content.split('\n')) {
    const line = rawLine.trim()
    if (!line) continue
    const heading = /^#{1,6}\s+(.+)$/u.exec(line)
    if (heading) {
      section = cleanText(heading[1], MAX_SECTION_CHARACTERS)
      continue
    }
    for (const part of splitLongParagraph(line, MAX_CHUNK_CHARACTERS)) blocks.push({ section, text: part })
  }
  const chunks = []
  let current = null
  for (const block of blocks) {
    if (!current || current.section !== block.section
      || current.text.length + block.text.length + 1 > MAX_CHUNK_CHARACTERS) {
      if (current) chunks.push(current)
      current = { section: block.section, text: block.text }
    } else {
      current.text += `\n${block.text}`
    }
  }
  if (current) chunks.push(current)
  return chunks
}

function chunkScore(chunk, aliasText, latin, bigrams, queryText) {
  const sectionScore = chunk.section
    ? scoreText(chunk.section, aliasText, latin, bigrams, queryText, 2)
    : 0
  return sectionScore + scoreText(chunk.text, aliasText, latin, bigrams, queryText)
}

function formatChunk(chunk) {
  return chunk.section ? `${chunk.section}\n${chunk.text}` : chunk.text
}

/**
 * Select the most relevant chunk of an article instead of cutting the content at
 * an arbitrary character offset. Chunks end at a paragraph or sentence boundary.
 */
export function excerptArticle(article, latin, bigrams, queryText = '') {
  const content = article.content
  if (content.length <= MAX_CHUNK_CHARACTERS) return content
  const aliasText = article.aliases.map(alias => alias.toLocaleLowerCase())
  const chunks = chunkArticle(article)
  if (!chunks.length) return ''
  const scored = chunks.map((chunk, index) => ({ chunk, index, score: chunkScore(chunk, aliasText, latin, bigrams, queryText) }))
  const matched = scored.filter(item => item.score > 0)
    .sort((a, b) => b.score - a.score || a.index - b.index)
    .slice(0, MAX_CHUNKS_PER_ARTICLE)
    .sort((a, b) => a.index - b.index)
  const chosen = matched.length ? matched : [scored[0]]
  let excerpt = ''
  for (const item of chosen) {
    const text = formatChunk(item.chunk)
    if (excerpt && excerpt.length + text.length + 2 > MAX_EXCERPT_CHARACTERS) break
    excerpt += `${excerpt ? '\n\n' : ''}${text}`
  }
  if (!excerpt) excerpt = formatChunk(chosen[0].chunk)
  return excerpt.length <= MAX_EXCERPT_CHARACTERS ? excerpt : excerpt.slice(0, MAX_EXCERPT_CHARACTERS)
}

function latinAliases(article) {
  const terms = []
  for (const alias of article.aliases) {
    for (const token of alias.match(/[A-Za-z][A-Za-z0-9_-]{2,}/g) || []) {
      const cleaned = token.toLocaleLowerCase()
      if (cleaned.length >= 3) terms.push(cleaned)
    }
  }
  return terms
}

/** Build the API-document query, adding host-owned English aliases for non-English questions. */
export function buildDocumentQuery(question, searchTerms = []) {
  const base = String(question || '').trim()
  const extra = [...new Set(searchTerms
    .map(term => String(term || '').trim().toLocaleLowerCase())
    .filter(term => /^[a-z0-9][a-z0-9_-]{2,}$/u.test(term)))]
    .slice(0, MAX_SEARCH_TERMS)
  return [base, ...extra].filter(Boolean).join(' ')
}

export function selectKnowledgeContext(articles, question, options = {}) {
  const maxArticles = Number.isInteger(options.maxArticles) && options.maxArticles > 0
    ? Math.min(options.maxArticles, MAX_SELECTED_ARTICLES)
    : MAX_SELECTED_ARTICLES
  const maxContextCharacters = Number.isInteger(options.maxContextCharacters) && options.maxContextCharacters >= 0
    ? Math.min(options.maxContextCharacters, MAX_CONTEXT_CHARACTERS)
    : MAX_CONTEXT_CHARACTERS
  const normalized = normalizeKnowledgeArticles(Array.isArray(articles) ? articles : [])
  const queryText = String(question || '').toLocaleLowerCase()
  const latin = latinTokens(queryText)
  const bigrams = cjkBigrams(queryText)
  if (!latin.length && !bigrams.length) return { selected: [], references: [], contextText: '', searchTerms: [] }
  const ranked = normalized
    .map(article => ({ article, score: scoreArticle(article, latin, bigrams, queryText) }))
    .filter(item => item.score > 0)
    .sort((a, b) => b.score - a.score
      || a.article.title.localeCompare(b.article.title)
      || a.article.id.localeCompare(b.article.id))
    .slice(0, maxArticles)
  const selected = []
  const searchTerms = []
  let contextText = ''
  for (const { article } of ranked) {
    const remaining = maxContextCharacters - contextText.length
    const label = article.module || article.kind
      ? ` (${[article.module, article.kind].filter(Boolean).join(' · ')})`
      : ''
    const heading = `[${selected.length + 1}] ${article.title}${label}\n`
    const separator = contextText ? '\n\n' : ''
    const availableExcerpt = remaining - separator.length - heading.length
    if (availableExcerpt < 1) break
    let excerpt = excerptArticle(article, latin, bigrams, queryText)
    if (excerpt.length > availableExcerpt) excerpt = excerpt.slice(0, availableExcerpt)
    // Citation carries only validated display metadata (id/title/module/kind).
    // sourcePath is validated for provenance debugging but never rendered as
    // a link or navigation target, so hostile host data cannot drive navigation.
    selected.push({
      id: article.id,
      title: article.title,
      module: article.module,
      kind: article.kind,
      locale: article.locale,
      sourcePath: article.sourcePath,
      excerpt
    })
    for (const term of latinAliases(article)) if (!searchTerms.includes(term)) searchTerms.push(term)
    contextText += `${separator}${heading}${excerpt}`
  }
  const references = selected.map(item => ({ id: item.id, title: item.title, module: item.module, kind: item.kind, sourcePath: item.sourcePath }))
  return { selected, references, contextText, searchTerms: searchTerms.slice(0, MAX_SEARCH_TERMS) }
}
