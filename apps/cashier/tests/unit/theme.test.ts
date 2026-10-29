import { describe, expect, it } from 'vitest'
import { defaultTheme, isThemeId, themeIds } from '../../shared/theme'
import { palettes, readSavedTheme, saveTheme, themeStorageKey, type ThemeStorage } from '../../src/lib/theme-preferences'

function storage(initial: string | null = null): ThemeStorage {
  const values = new Map(initial === null ? [] : [[themeStorageKey, initial]])
  return { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => { values.set(key, value) } }
}

function luminance(hex: string): number {
  const rgb = hex.slice(1).match(/../g)!.map((part) => parseInt(part, 16) / 255)
    .map((value) => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)
  return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722
}
function contrast(a: string, b: string): number {
  const values = [luminance(a), luminance(b)].sort((a, b) => b - a)
  return (values[0] + 0.05) / (values[1] + 0.05)
}

describe('local color preference', () => {
  it.each(themeIds)('persists and restores %s', (theme) => {
    const target = storage()
    expect(saveTheme(target, theme)).toBe(true)
    expect(target.getItem(themeStorageKey)).toBe(theme)
    expect(readSavedTheme(target)).toBe(theme)
    expect(isThemeId(theme)).toBe(true)
  })
  it.each([null, '', 'invalid', 'GREEN', '<script>', '{"theme":"blue"}'])('falls back for invalid stored value %s', (value) => {
    expect(readSavedTheme(storage(value))).toBe(defaultTheme)
  })
  it('tolerates blocked or full storage', () => {
    const blocked: ThemeStorage = { getItem() { throw new Error('blocked') }, setItem() { throw new Error('full') } }
    expect(readSavedTheme(blocked)).toBe(defaultTheme)
    expect(saveTheme(blocked, 'blue')).toBe(false)
    expect(readSavedTheme(null)).toBe(defaultTheme)
    expect(saveTheme(null, 'blue')).toBe(false)
    expect(isThemeId(null)).toBe(false)
  })
  it.each(themeIds)('%s keeps action and navigation labels at AA contrast', (theme) => {
    const palette = palettes[theme]
    expect(contrast(palette.primary, '#ffffff')).toBeGreaterThanOrEqual(4.5)
    expect(contrast(palette.sidebar, palette.accent)).toBeGreaterThanOrEqual(4.5)
  })
})
