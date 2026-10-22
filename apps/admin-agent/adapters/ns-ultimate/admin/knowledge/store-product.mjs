export const storeProductArticle = {
  id: 'ns-store-product',
  title: 'Product catalog fields and sale settings',
  module: 'Store',
  kind: 'guide',
  locale: 'en',
  aliases: ['product', 'products', 'specification', 'sold out', 'recommendation', '商品', '产品', '菜品', '规格', '售罄', '推荐'],
  sourcePath: 'business/admin/config/overrides/product/product.jsx',
  content: [
    'Supported: the business Product form carries name, an optional text description, an optional category, and an optional owning store selected by UUID; leave the store empty for a global product. Display controls are sortOrder (default 0), recommendationLevel (default 0) and isSoldout (default false); status is active or inactive (default active). The list can filter by name, category, status and deleted state, and the name column shows the cover thumbnail when set.',
    'Metadata: subtitle is free text and cover is an image uploaded to Qiniu storage, persisted as metadata.cover (an https URL or /uploads/images/ path). Product behavior settings live in settings: kitchenPrinterEnable marks items for the kitchen-filtered print list, and startSaleTime/endSaleTime use 24-hour HH:mm format (empty means no bound).',
    'Specifications: on a new product, specifications are entered inline in the form tab; after the product is saved, they are managed through the product specifications list (name, price, sort) under /api/v1/manage/products/{id}/specifications.',
    'Troubleshooting: if a product never appears on a filtered kitchen ticket, check settings.kitchenPrinterEnable; if sale-time gating misbehaves, confirm both times are valid HH:mm strings rather than descriptive text.',
    'Assistant boundary: Product writes are not in the assistant mutation allowlist. Creating or editing products, uploading covers and managing specifications are manual operator steps in the Admin UI.'
  ].join('\n')
}
