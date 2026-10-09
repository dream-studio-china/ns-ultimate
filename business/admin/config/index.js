import { collectEntities } from './collect-entities.mjs'

const collections = import.meta.glob('./collections/**/*.{js,jsx}', { eager: true })
const entities = collectEntities(collections)

export default {
  add: entities,
  replace: {},
  remove: []
}
