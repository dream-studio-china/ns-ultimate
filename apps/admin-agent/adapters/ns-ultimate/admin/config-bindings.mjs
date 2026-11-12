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

/**
 * Printer order rules use custom editors but store plain rule data. The
 * binding documents the stored value type for each control; shape rules live
 * in the printer workbench validator, because they are domain semantics rather
 * than generic form metadata.
 */
export const printerComponentBindings = Object.freeze({
  PrinterOrderRule: Object.freeze({
    events: Object.freeze({ componentName: 'OrderPrintEvents', type: 'array' }),
    conditions: Object.freeze({ componentName: 'OrderPrintConditions', type: 'array' }),
    deviceUuid: Object.freeze({ componentName: 'OrderPrintOutputSelect', type: 'relation', relation: true }),
    templateUuid: Object.freeze({ componentName: 'OrderPrintOutputSelect', type: 'relation', relation: true })
  })
})

/** Component bindings passed to the shared configuration projection. */
export const componentBindings = Object.freeze({
  ...scalarComponentBindings,
  ...printerComponentBindings
})
