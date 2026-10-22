// ns-ultimate host preview-only pilot. This policy never authorizes a backend write.
export default {
  Counter: { name: { label: 'Name', type: 'string', required: true, maxLength: 120 } }
}
