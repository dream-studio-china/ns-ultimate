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
    'Supported proposals: Content (title, body, category, tags, metadata, sortOrder) is the only entity with proposed create, update and delete actions, always subject to operator preview and confirmation. Counter has only a name draft preview that never authorizes a backend write.',
    'Manual-only operations: printer device registration, status refresh, job submission, job preview, reprints and the automatic-printing dispatch command are operator actions in the Admin UI or shell with confirmation guards. WeChat credential entry, profile creation and payment enablement are operator actions. Store, product, counter and order writes are operator actions. The assistant cannot execute console commands, call provider APIs, or handle secrets.',
    'Credentials: never share developer keys, AppSecrets, API v3 keys, certificates or private keys with the assistant. Enter them in the admin password fields; on edit, leaving a credential blank keeps the current value, and stored credentials are encrypted and never returned.'
  ].join('\n')
}
