// ns-ultimate host allowlist for bounded single-record content reads.
// Only these entities and fixed fields may leave the host through the read
// tool. Body text is sent to the configured model provider and must be treated
// as untrusted content; credentials, contact data and opaque configuration
// blobs stay outside this policy.
export const recordReadPolicy = {
  Content: { fields: ['title', 'body', 'createdAt', 'updatedAt'] },
  Page: { fields: ['title', 'slug', 'status', 'body', 'publishedAt'] }
}

// Additional safe list projections and filters needed for relations that are
// represented by nested manager endpoints rather than ordinary form fields.
export const readCatalogPolicy = {
  Specification: {
    fields: [{ property: 'product.id', label: 'Product ID', type: 'integer' }],
    filters: [{ property: 'product.id', label: 'Product ID', type: 'integer' }]
  }
}

export default recordReadPolicy
