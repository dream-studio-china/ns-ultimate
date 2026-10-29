export const themeIds = ['red', 'orange', 'yellow', 'green', 'blue', 'indigo', 'purple'] as const
export type ThemeId = typeof themeIds[number]
export const defaultTheme: ThemeId = 'green'

export function isThemeId(value: unknown): value is ThemeId {
  return typeof value === 'string' && themeIds.some((id) => id === value)
}
