import { describe, expect, it } from 'vitest'
import { categorySlug } from '../../src/staff/live/category-slug'

describe('categorySlug', () => {
  it('creates an ASCII slug from a readable name and a stable unique suffix', () => {
    expect(categorySlug('  Café & drinks  ', '12345678-1234-4234-8234-123456789abc'))
      .toBe('cafe-drinks-12345678123442348234123456789abc')
  })

  it('uses a safe fallback for names without Latin characters', () => {
    expect(categorySlug('茶饮', 'abcdef12-3456-4789-8abc-def012345678'))
      .toBe('category-abcdef12345647898abcdef012345678')
  })
})
