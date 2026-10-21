import { relationIdentifier, relationLabel, resolveRelation } from '@/utils/relation-descriptor'

const entities = { User: {}, Store: {}}

describe('utils/relation', () => {
  it('keeps conventional metadata relations keyed by id', () => {
    expect(resolveRelation(
      { property: 'role' },
      { metadata: { type: 'ManyToOne', targetEntity: 'App\\Entity\\Role' }},
      { Role: {}}
    )).toMatchObject({ name: 'Role', valueKey: 'id', multiple: false })
  })

  it('infers registered singular UUID relation fields', () => {
    expect(resolveRelation({ property: 'userUuid' }, {}, entities))
      .toMatchObject({ name: 'User', valueKey: 'uuid', multiple: false })
  })

  it.each(['string', 'text'])('preserves UUID inference with metadata type %s', (type) => {
    expect(resolveRelation({ property: 'storeUuid' }, { metadata: { type }}, entities))
      .toMatchObject({ name: 'Store', valueKey: 'uuid', multiple: false })
    expect(resolveRelation({ property: 'storeUuids' }, { metadata: { type }}, entities))
      .toMatchObject({ name: 'Store', valueKey: 'uuid', multiple: true })
  })

  it.each(['text', 'string'])('does not infer UUID relations with explicit scalar type %s', (type) => {
    expect(resolveRelation({ property: 'storeUuid', type }, { metadata: { type: 'text' }}, entities)).toBeNull()
    expect(resolveRelation({ property: 'storeUuids', type }, { metadata: { type: 'text' }}, entities)).toBeNull()
  })

  it.each(['text', 'string'])('uses explicit relation targets ahead of scalar type %s', (type) => {
    expect(resolveRelation({
      property: 'userUuid',
      type,
      relation: { target: 'Store', valueKey: 'uuid' }
    }, { metadata: { targetEntity: 'App\\Entity\\User' }}, entities))
      .toMatchObject({ name: 'Store', valueKey: 'uuid', multiple: false })
  })

  it.each(['text', 'string'])('uses conventional relation targets ahead of scalar type %s', (type) => {
    expect(resolveRelation({ property: 'userUuid', type }, {
      metadata: { type: 'ManyToOne', targetEntity: 'App\\Entity\\Store' }
    }, entities)).toMatchObject({ name: 'Store', valueKey: 'id', multiple: false })
    expect(resolveRelation({
      property: 'userUuid',
      type,
      type_options: { entity_name: 'Store' }
    }, {}, entities)).toMatchObject({ name: 'Store', valueKey: 'id', multiple: false })
  })

  it('infers registered multi-value UUID relation fields', () => {
    expect(resolveRelation({ property: 'userUuids' }, {}, entities))
      .toMatchObject({ name: 'User', valueKey: 'uuid', multiple: true })
  })

  it('does not infer unknown or polymorphic UUID fields', () => {
    expect(resolveRelation({ property: 'actorUuid' }, {}, entities)).toBeNull()
    expect(resolveRelation({ property: 'uuid' }, {}, entities)).toBeNull()
  })

  it('uses explicit relation settings ahead of suffix inference', () => {
    expect(resolveRelation({
      property: 'scopeUuid',
      relation: { entity: { name: 'Store', plural: 'stores', prefix: '/api/v1/manage' }, valueKey: 'uuid' }
    }, {}, entities)).toEqual({
      name: 'Store',
      plural: 'stores',
      prefix: '/api/v1/manage',
      valueKey: 'uuid',
      multiple: false
    })
  })

  it('allows explicit id values for fields with a UUID suffix', () => {
    expect(resolveRelation({
      property: 'legacyUserUuid',
      relation: { entity: 'User', valueKey: 'id' }
    }, {}, entities)).toMatchObject({ name: 'User', valueKey: 'id' })
  })

  it('uses user identifiers when an API response omits __toString', () => {
    expect(relationLabel({ id: 1, username: 'alice' })).toBe('alice')
  })

  it('prefers the relation value key for detail links', () => {
    const record = { id: 1, uuid: '550e8400-e29b-41d4-a716-446655440000' }

    expect(relationIdentifier(record, { valueKey: 'id' })).toEqual({ key: 'id', value: 1 })
    expect(relationIdentifier(record, { valueKey: 'uuid' })).toEqual({ key: 'uuid', value: record.uuid })
  })
})
