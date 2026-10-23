/** Resolve configured controls with the same metadata/relation helpers as FormAdmin. */
export function resolveFormConfiguration(config, structure, { resolveRelation, entities }) {
  if (!structure) return { ...config, form: undefined }
  const configured = config.form?.fields
  let fields = configured
  if (configured === '__all__') fields = Object.keys(structure)
  else if (Array.isArray(configured) && configured.includes('__all__')) {
    const explicit = configured.filter(field => typeof field !== 'string')
    const properties = new Set(explicit.map(field => field.property))
    fields = [...explicit, ...Object.keys(structure).filter(property => !properties.has(property))]
  }
  if (!Array.isArray(fields)) return config
  return { ...config, form: { ...config.form, fields: fields
    .filter(field => (typeof field === 'string' ? field : field.property) !== 'id')
    .map(raw => {
      const field = typeof raw === 'string' ? { property: raw } : raw
      const metadata = structure[field.property]?.metadata
      const resolvedRelation = resolveRelation(field, structure[field.property], entities)
      const relation = resolvedRelation && (field.relation || !field.type || /^(?:relation|manyto|oneto)/i.test(field.type)) ? resolvedRelation : null
      return { ...field,
        type: relation ? 'relation' : field.type || metadata?.type || 'string',
        required: field.required ?? (metadata ? metadata.nullable === false : false),
        ...(relation ? { relation: { target: relation.name, valueKey: relation.valueKey, multiple: relation.multiple } } : {})
      }
    }) } }
}
