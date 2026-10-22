export const supportBoundariesArticle = {
  id: 'ns-agent-support-boundaries',
  title: 'What the assistant can and cannot do for business modules',
  module: 'Agent',
  kind: 'guide',
  locale: 'en',
  aliases: ['assistant', 'agent help', 'support boundary', 'permissions', '助手', '智能助手', '能做什么', '权限边界'],
  sourcePath: 'apps/admin-agent/adapters/ns-ultimate/admin/mutation-capabilities.mjs',
  content: [
    'Knowledge versus capability: these articles are knowledge only. They explain setup, use and troubleshooting, but reading an article never grants the assistant the ability to act. Anything the assistant does still passes through the generic capability, preview and confirmation machinery.',
    'Supported proposals are defined by the current host capability catalog, not this guide. The business-management rollout covers Content, Counter, Category, Tag, ProductCategory, Product, Specification and Store through explicit adapters. An operation is available only when its entity, action and fields are actually registered. Proposals require backend preparation, operator review of the full differences, explicit UI confirmation and backend commit; the model cannot submit writes.',
    'Business boundaries: approved catalog operations can change prices, availability, hierarchy and public presentation, so these are consequential business edits, not harmless annotations. Targets and scope must be exact; deletions must pass reference checks and must not silently cascade. Product deletion is not enabled. Store operations are limited to name and structured contact/address maintenance; they do not grant access to settings, lifecycle, timezone or currency. Existing Content JSON support remains narrowly validated and does not authorize arbitrary JSON on other entities.',
    'Manual-only operations: printer device registration, status refresh, job submission, reprints and automatic dispatch remain outside assistant capabilities; printing development is paused. WeChat credentials, payment/refund, inventory, order workflow, identity and authorization changes, cross-store reassignment and bulk writes are excluded. The assistant cannot execute console commands, call provider APIs, handle secrets or infer permission from an article.',
    'Credentials: never share developer keys, AppSecrets, API v3 keys, certificates or private keys with the assistant. Enter them in the admin password fields; on edit, leaving a credential blank keeps the current value, and stored credentials are encrypted and never returned.'
  ].join('\n')
}
