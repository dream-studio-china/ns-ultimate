export const contentArticle = {
  id: 'ns-content-guide',
  title: 'Content fields and assistant editing support',
  module: 'Common',
  kind: 'guide',
  locale: 'en',
  aliases: ['content', 'article', 'category', 'tags', '内容', '文章', '内容管理', '分类', '标签'],
  sourcePath: 'business/backend/Common/resources/json-schema/ContentMetadata.json',
  content: [
    'Supported: the business Content entity extends the core content record with a sortOrder integer for display ordering. Content metadata accepts only two optional keys: featured (boolean) and layout (article or landing); any other key is rejected. Other fields: title is a non-empty string of at most 255 characters and is required on create; body is a string or null of at most 20000 UTF-8 bytes, shown as plain-text preview because HTML is not rendered; category is an existing category numeric ID or null; tags is an array of existing tag numeric IDs, at most 50; sortOrder is a signed 32-bit integer.',
    'Assistant support: Content is the only entity in the assistant mutation allowlist, with proposed create, update and delete actions. Proposals still go through the assistant preview and operator confirmation flow; the assistant never writes directly. Category and tag IDs must reference records that already exist; never invent an ID, and ask the operator to create or pick the right one first.',
    'Troubleshooting: if a save is rejected, check that metadata holds only featured and layout, that title is non-empty within 255 characters, that body fits 20000 UTF-8 bytes, and that category and tags point at existing records.'
  ].join('\n')
}
