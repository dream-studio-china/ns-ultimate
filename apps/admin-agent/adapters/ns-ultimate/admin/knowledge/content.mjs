export const contentArticle = {
  id: 'ns-content-guide',
  title: 'Content fields and assistant editing support',
  module: 'Common',
  kind: 'guide',
  locale: 'en',
  aliases: ['content', 'article', 'category', 'tags', '内容', '文章', '内容管理', '分类', '标签'],
  sourcePath: 'business/backend/Common/resources/json-schema/ContentMetadata.json',
  content: [
    'Domain model: the business Content subtype adds sortOrder. Its backend metadata schema accepts featured (boolean) and layout (article or landing). Domain validation still applies even when a JSON control is editable. Title, body, category and tags are supplied through the existing Content CRUD API; relations must reference existing records.',
    'Assistant support: fields and actions come from the effective admin forms for Content, Page, Category, Tag and Comment, subject to agent policy and component support. There is no separate Agent body-length or metadata-key table. Local preparation does not validate backend business rules or persist a record. Review the full preview and explicitly confirm. Never invent relation IDs or infer IDs from create placeholders.',
    'Troubleshooting: use the concrete validation reason returned by the existing backend. Confirm that the field is configured, relations exist and domain metadata validation passes. A successful response acknowledges the save; a timeout is unknown and must not trigger an automatic retry.'
  ].join('\n')
}
