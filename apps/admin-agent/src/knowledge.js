export const MAX_KNOWLEDGE_SOURCE_ARTICLES = 200
export const MAX_SELECTED_ARTICLES = 4
export const MAX_EXCERPT_CHARACTERS = 2000
export const MAX_CONTEXT_CHARACTERS = 8000

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

function scoreArticle(article, latin, bigrams, queryText) {
  const title = article.title.toLocaleLowerCase()
  const aliasText = article.aliases.map(alias => alias.toLocaleLowerCase())
  const body = article.content.toLocaleLowerCase()
  let score = 0
  for (const alias of aliasText) {
    if (alias.length >= 2 && queryText.includes(alias)) score += 4
  }
  for (const token of latin) {
    if (title.includes(token)) score += 3
    else if (aliasText.some(alias => alias.includes(token))) score += 2
    else if (body.includes(token)) score += 1
  }
  for (const bigram of bigrams) {
    if (title.includes(bigram)) score += 2
    else if (aliasText.some(alias => alias.includes(bigram))) score += 2
    else if (body.includes(bigram)) score += 1
  }
  return score
}

function firstMatchPosition(contentLower, latin, bigrams, aliasText) {
  let position = -1
  const consider = index => {
    if (index >= 0 && (position === -1 || index < position)) position = index
  }
  for (const token of latin) consider(contentLower.indexOf(token))
  for (const bigram of bigrams) consider(contentLower.indexOf(bigram))
  for (const alias of aliasText) {
    if (alias.length >= 2) consider(contentLower.indexOf(alias))
  }
  return position
}

export function excerptArticle(article, latin, bigrams) {
  const content = article.content
  if (content.length <= MAX_EXCERPT_CHARACTERS) return content
  const contentLower = content.toLocaleLowerCase()
  const aliasText = article.aliases.map(alias => alias.toLocaleLowerCase())
  const position = firstMatchPosition(contentLower, latin, bigrams, aliasText)
  const start = position === -1 ? 0 : Math.max(0, position - 400)
  const excerpt = content.slice(start, start + MAX_EXCERPT_CHARACTERS)
  return `${start > 0 ? '…' : ''}${excerpt}${start + excerpt.length < content.length ? '…' : ''}`.slice(0, MAX_EXCERPT_CHARACTERS + 2)
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
  if (!latin.length && !bigrams.length) return { selected: [], references: [], contextText: '' }
  const ranked = normalized
    .map(article => ({ article, score: scoreArticle(article, latin, bigrams, queryText) }))
    .filter(item => item.score > 0)
    .sort((a, b) => b.score - a.score
      || a.article.title.localeCompare(b.article.title)
      || a.article.id.localeCompare(b.article.id))
    .slice(0, maxArticles)
  const selected = []
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
    let excerpt = excerptArticle(article, latin, bigrams)
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
    contextText += `${separator}${heading}${excerpt}`
  }
  const references = selected.map(item => ({ id: item.id, title: item.title, module: item.module, kind: item.kind, sourcePath: item.sourcePath }))
  return { selected, references, contextText }
}
