export const counterArticle = {
  id: 'ns-counter-guide',
  title: 'Counter management and order binding',
  module: 'Counter',
  kind: 'guide',
  locale: 'en',
  aliases: ['counter', 'counters', 'order counter', 'table', '柜台', '桌台', '取餐柜'],
  sourcePath: 'business/backend/Counter/README.md',
  content: [
    'Supported: Counter owns store-scoped counter records and the many-to-many binding between counters and Trade orders. Administrators manage counters under /api/v1/manage/counters; store staff use /api/v1/store/{scopeId}/counters, where the store UUID comes from the authorized scope rather than request data. Administrator creation requires storeUuid, code and name; capacity, sort, metadata and enabled are optional. Counter codes are unique within a store, sort is a non-negative integer for display ordering, and store ownership cannot be changed through the store update endpoint.',
    'Customer and staff views: authenticated customers list enabled counters for the active store context at /api/v1/app/counters, selected with the X-Store-Code header; without a store context the list is empty. Authenticated app users can list the current day non-closed orders for a counter; the store timezone defines the day, completed/cancelled/refunded orders are excluded, and the response omits customer details and internal item cost/profit. Store staff list, bind and unbind order counters under /api/v1/store/{scopeId}/order-counters; administrators inspect bindings at /api/v1/manage/order-counters, which are immutable. The Admin order list and detail show a read-only counter number column.',
    'Binding at order creation: include the counter UUID in order metadata (counterUuid for one counter, counterUuids as a list for several). The adapter takes store ownership from the Trade order store lifecycle record, validates that each counter is enabled and belongs to that store, then creates OrderCounter rows in the same flush; invalid references fail the order transaction. Orders without counter metadata are unaffected. OrderCounter snapshots the counter code and name.',
    'Setup: run app:counter:seed-authorization after migrations to register store-scoped permissions and the store_counter_manager system role, then assign the role through the existing Authorization workflow.',
    'Assistant boundary: configured Counter form fields drive local previews and confirmed requests to the existing admin CRUD API. Backend validation enforces required fields, store scope and code uniqueness. Do not invent Store UUIDs. Counter deletion is prohibited by agent policy. Configured creation supports sequential batches, not a transaction; earlier rows may succeed before a failure. Disabling a counter affects new selection, not existing order bindings. No custom bind, print or payment endpoint is exposed.'
  ].join('\n')
}
