export function categorySlug(name: string, seed: string): string {
  const base = name
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60)
    .replace(/-+$/g, '')
  const suffix = seed.replace(/[^a-z0-9]/gi, '').toLowerCase().slice(0, 32)
  return `${base || 'category'}-${suffix}`
}
