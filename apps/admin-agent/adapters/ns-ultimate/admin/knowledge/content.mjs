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
    'Assistant support: Content, Category, Tag and ProductCategory are among the entities in the current catalog, each limited to its actually registered actions and fields. Content editing still accepts only the fields above, and its narrow JSON metadata support is not a general metadata permission for other entities. Proposals still go through backend preparation, the assistant preview and explicit operator confirmation; the assistant never writes directly. Category and tag IDs must reference records that already exist; never invent an ID. Preparation alone does not persist a record: first complete explicit user confirmation and a successful backend commit for the dedicated Category or Tag operation, then query the persisted record or use the confirmed persisted ID before referencing it from Content. Never infer an ID from a preview or treat a preview new-record placeholder as an existing reference.',
    'Troubleshooting: if a save is rejected, check that metadata holds only featured and layout, that title is non-empty within 255 characters, that body fits 20000 UTF-8 bytes, and that category and tags point at existing records.'
  ].join('\n')
}
