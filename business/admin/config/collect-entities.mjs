export function collectEntities(collections) {
  const entities = {}

  for (const [collectionPath, module] of Object.entries(collections)) {
    const entityPath = collectionPath.replace(/^\.\/collections\/(.*)\.\w+$/, '$1')
    const pathArray = entityPath.split('/')
    const collection = module.default

    if (pathArray.length === 2) {
      for (const [name, config] of Object.entries(collection)) {
        if (Object.hasOwn(entities, name)) {
          throw new Error(`Duplicate business entity configuration "${name}".`)
        }
        entities[name] = config
      }
    } else if (pathArray.length > 2) {
      const name = pathArray.at(-1)
      if (Object.hasOwn(entities, name)) {
        throw new Error(`Duplicate business entity configuration "${name}".`)
      }
      entities[name] = collection
    }
  }

  return entities
}
