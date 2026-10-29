export const storeSettingsArticle = {
  id: 'ns-store-settings',
  title: 'Store settings and order lifecycle options',
  module: 'Store',
  kind: 'guide',
  locale: 'en',
  aliases: ['store', 'store settings', 'order acceptance', 'fulfillment', 'payment rate', '门店', '店铺', '门店设置', '订单设置'],
  sourcePath: 'business/admin/config/overrides/store/StoreSettings.json',
  content: [
    'Supported: the business Store form requires code, name and status (activate, suspend or close); timezone defaults to Asia/Shanghai; contact, address, settings and payment setting stay optional. Store deletion is disabled in the Admin list. Payment rate is a decimal fraction (0.006 means 0.6 percent).',
    'Order lifecycle: settings.order.requireAcceptance, when true, makes Trade confirmation wait for the store acceptance fact; otherwise confirmation follows payment. settings.order.requireInventory, when true, makes the order await an inventory reservation before acceptance. settings.fulfillment.requireVerification, when true, requires a fulfilled order to be verified before completion.',
    'Automatic printing is configured with store-scoped Printer order rules, not Store settings. Legacy settings.printing values are not automatically migrated or deleted; recreate the desired rules explicitly. See the automatic Trade order printing guide.',
    'Assistant boundary: editable Store fields come from the effective form configuration, minus agent prohibitions. Schema-form patches preserve omitted nested values; ordinary JSON controls replace their supplied values. There is no separate StorePrinting pseudo entity. Settings changes can affect future operations, so explain that impact and review the full local preview before UI confirmation. Existing backend validation remains authoritative; preparation is not server validation.'
  ].join('\n')
}
