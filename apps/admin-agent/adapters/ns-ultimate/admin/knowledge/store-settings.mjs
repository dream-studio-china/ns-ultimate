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
    'Automatic printing: settings.printing holds the five fixed cases order, perItem, filteredList, fullList and settlement, each with enabled, deviceUuid, templateUuid and copies (1-100, default 1). Enabling a case affects future order processing only and does not print now or create a print job. See the automatic Trade order printing guide for behavior and timing; the store form only enables a case and picks its device and template.',
    'Assistant boundary: Store name and structured contact/address are in the current catalog for the Store entity, while settings, status, payment configuration, timezone and currency remain manual operator steps. Automatic printing is a separate StorePrinting capability that, when registered, can update only the five printing cases and their enabled, deviceUuid, templateUuid and copies fields. Each proposal is a patch: omitted cases, omitted fields within a changed case, and every unrelated Store setting are preserved from the stored settings, and the settings root is never replaced wholesale or accepted as input. The assistant can explain the options and look up store records.'
  ].join('\n')
}
