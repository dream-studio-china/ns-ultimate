/**
 * Explicit host bindings for semantic capabilities whose runtime field names do
 * not correspond to top-level crud-admin form properties.
 *
 * These are configuration paths, not additional field rules. The shared
 * projection validates every path against the effective composed Store/Product
 * JSON schema; an absent or renamed path drops the semantic capability instead
 * of synthesizing a pseudo entity. The build-time SSR manifest exporter imports
 * this same object so the frontend and generated artifact cannot drift.
 */

export const semanticCapabilityBindings = Object.freeze({
  StorePrinting: Object.freeze({
    entity: 'Store',
    actions: Object.freeze(['update']),
    fields: Object.freeze({
      order: 'settings.printing.order',
      perItem: 'settings.printing.perItem',
      filteredList: 'settings.printing.filteredList',
      fullList: 'settings.printing.fullList',
      settlement: 'settings.printing.settlement'
    })
  }),
  ProductPrinting: Object.freeze({
    entity: 'Product',
    actions: Object.freeze(['update']),
    fields: Object.freeze({ kitchenPrinterEnable: 'settings.kitchenPrinterEnable' })
  })
})

/**
 * Explicit scalar UI-component bindings. Matching requires the exact entity,
 * property, and configured component.name. No component callback is executed
 * and no implicit value (such as an auto-generated slug) is supplied.
 */
export const scalarComponentBindings = Object.freeze({
  ProductCategory: Object.freeze({
    name: Object.freeze({ componentName: 'ProductCategoryNameField', type: 'string' })
  })
})
