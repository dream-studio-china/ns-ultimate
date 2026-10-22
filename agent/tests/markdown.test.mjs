import assert from 'node:assert/strict'
import test from 'node:test'
import { parseInlineMarkdown, parseMarkdown } from '../src/markdown.js'

test('parses common inline formatting, links, and leaves raw HTML as text', () => {
  assert.deepEqual(parseInlineMarkdown('**bold** and *italic* `code` [docs](https://example.test/guide) <img src=x>'), [
    { type: 'strong', children: [{ type: 'text', value: 'bold' }] },
    { type: 'text', value: ' and ' },
    { type: 'em', children: [{ type: 'text', value: 'italic' }] },
    { type: 'text', value: ' ' },
    { type: 'code', value: 'code' },
    { type: 'text', value: ' ' },
    { type: 'link', href: 'https://example.test/guide', children: [{ type: 'text', value: 'docs' }] },
    { type: 'text', value: ' <img src=x>' }
  ])
})

test('does not create links for unsafe URL schemes and renders images as alt text', () => {
  assert.deepEqual(parseInlineMarkdown('[unsafe](javascript:alert(1)) ![diagram](https://example.test/image.png)'), [
    { type: 'text', value: 'unsafe' },
    { type: 'text', value: ') ' },
    { type: 'text', value: 'diagram' }
  ])
})

test('parses headings, paragraphs, lists, quotes, rules, and fenced code blocks', () => {
  assert.deepEqual(parseMarkdown('# Summary\nA **short** intro.\n\n- first\n- second\n\n> quoted\n\n---\n\n```js\nalert(1)\n```'), [
    { type: 'heading', level: 1, children: [{ type: 'text', value: 'Summary' }] },
    { type: 'paragraph', children: [
      { type: 'text', value: 'A ' },
      { type: 'strong', children: [{ type: 'text', value: 'short' }] },
      { type: 'text', value: ' intro.' }
    ] },
    { type: 'list', ordered: false, start: 1, items: [[{ type: 'text', value: 'first' }], [{ type: 'text', value: 'second' }]] },
    { type: 'quote', children: [{ type: 'paragraph', children: [{ type: 'text', value: 'quoted' }] }] },
    { type: 'rule' },
    { type: 'codeBlock', language: 'js', value: 'alert(1)' }
  ])
})

test('parses GFM tables with inline formatting and column alignment', () => {
  assert.deepEqual(parseMarkdown('| Name | Count | Note |\n| :--- | ---: | :---: |\n| **Tea** | 3 | hot \\| cold |\n| Coffee | 12 | `dark` |'), [
    {
      type: 'table',
      headers: [[{ type: 'text', value: 'Name' }], [{ type: 'text', value: 'Count' }], [{ type: 'text', value: 'Note' }]],
      alignments: ['left', 'right', 'center'],
      rows: [
        [[{ type: 'strong', children: [{ type: 'text', value: 'Tea' }] }], [{ type: 'text', value: '3' }], [{ type: 'text', value: 'hot \\| cold' }]],
        [[{ type: 'text', value: 'Coffee' }], [{ type: 'text', value: '12' }], [{ type: 'code', value: 'dark' }]]
      ]
    }
  ])
})
