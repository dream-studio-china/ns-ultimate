export const PALETTE_STORAGE_KEY = 'ns_admin_palette'

export const palettes = [
  { value: 'default', label: 'Original palette', color: '#2563eb' },
  { value: 'emerald', label: 'Emerald green', color: '#047857' },
  { value: 'violet', label: 'Violet purple', color: '#7c3aed' },
  { value: 'orange', label: 'Warm orange', color: '#c2410c' },
  { value: 'rose', label: 'Rose red', color: '#be123c' },
  { value: 'sky', label: 'Deep sky blue', color: '#0369a1' },
  { value: 'teal', label: 'Teal green', color: '#0f766e' },
  { value: 'indigo', label: 'Indigo blue', color: '#4338ca' },
  { value: 'amber', label: 'Amber gold', color: '#a16207' },
  { value: 'slate', label: 'Slate gray', color: '#475569' },
  { value: 'pink', label: 'Cherry pink', color: '#be185d' }
]

function normalizePalette(value) {
  return palettes.some(palette => palette.value === value) ? value : 'default'
}

export function getPalette() {
  try {
    return normalizePalette(localStorage.getItem(PALETTE_STORAGE_KEY))
  } catch {
    return 'default'
  }
}

export function applyPalette(value, persist = true) {
  const palette = normalizePalette(value)
  if (palette === 'default') {
    delete document.documentElement.dataset.adminPalette
  } else {
    document.documentElement.dataset.adminPalette = palette
  }
  if (persist) {
    try {
      localStorage.setItem(PALETTE_STORAGE_KEY, palette)
    } catch {
      // Keep the selection usable when browser storage is unavailable.
    }
  }
  return palette
}

export function initializePalette() {
  return applyPalette(getPalette(), false)
}
