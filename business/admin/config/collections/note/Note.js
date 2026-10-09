export default {
  Note: {
    form: {
      fields: [
        { property: 'title', required: true },
        { property: 'body', type: 'text', required: false }
      ]
    },
    list: {
      list_display: ['id', 'title', 'createdAt']
    },
    detail: {
      detail_display: '__all__'
    }
  }
}
