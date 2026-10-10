import { beforeEach, describe, expect, it, vi } from 'vitest'

const { listMock } = vi.hoisted(() => ({ listMock: vi.fn() }))

vi.mock('@/easyadmin/adapters/crudskeleton/CrudSkeletonAdapter', () => ({
  CrudSkeletonAdapter: class {
    list(...args) { return listMock(...args) }
  }
}))

import { isCategorySlugAvailable, slugFromName, uniqueCategorySlug, updateCategoryName } from '@/configs/collections/trade/ProductCategoryNameField'

describe('ProductCategoryNameField', () => {
  beforeEach(() => listMock.mockReset())

  it('creates a normalized slug while preserving non-Latin letters', () => {
    expect(slugFromName('  Café & 茶饮  ')).toBe('cafe-cha-yin')
  })

  it('checks availability within the selected store scope', async() => {
    listMock.mockResolvedValue({ data: [] })

    await expect(isCategorySlugAvailable('coffee', 'store-uuid')).resolves.toBe(true)
    expect(listMock).toHaveBeenCalledWith({
      '@filter': "entity.getSlug() == 'coffee' && entity.getStore().getUuid() == 'store-uuid'",
      page: 1,
      limit: 1
    })
  })

  it('uses the selected store relation id when the form stores relation ids', async() => {
    listMock.mockResolvedValue({ data: [{ id: 1 }] })

    await expect(isCategorySlugAvailable('coffee', 17)).resolves.toBe(false)
    expect(listMock).toHaveBeenCalledWith({
      '@filter': "entity.getSlug() == 'coffee' && entity.getStore().getId() == 17",
      page: 1,
      limit: 1
    })
  })

  it('uses a numeric suffix rather than generating a duplicate slug', async() => {
    listMock
      .mockResolvedValueOnce({ data: [{ id: 3 }] })
      .mockResolvedValueOnce({ data: [] })
    const form = { name: '', slug: '', store: null }

    await updateCategoryName(form, 'name', 'Drinks', { value: 0 })

    expect(form.name).toBe('Drinks')
    expect(form.slug).toBe('drinks-2')
  })

  it('adds the first available numeric suffix when the base slug is taken', async() => {
    listMock
      .mockResolvedValueOnce({ data: [{ id: 1 }] })
      .mockResolvedValueOnce({ data: [{ id: 2 }] })
      .mockResolvedValueOnce({ data: [] })

    await expect(uniqueCategorySlug('茶饮', null)).resolves.toBe('cha-yin-3')
    expect(listMock).toHaveBeenCalledTimes(3)
  })

  it('does not overwrite a manually entered slug', async() => {
    const form = { name: '', slug: 'my-slug', store: null }

    await updateCategoryName(form, 'name', 'Drinks', { value: 0 })

    expect(form.slug).toBe('my-slug')
    expect(listMock).not.toHaveBeenCalled()
  })
})
