import { mount } from '@vue/test-utils'
import DetailCode from '@/easyadmin/ui/vue/plugins/detail/code.vue'

describe('detail-code plugin', () => {
  it.each([
    ['omitted value', {}, '-'],
    ['explicit undefined', { value: undefined }, '-'],
    ['null', { value: null }, '-'],
    ['empty string', { value: '' }, '-'],
    ['plain string', { value: 'const answer = 42' }, 'const answer = 42'],
    ['JSON-looking string', { value: '{"answer":42}' }, '{"answer":42}'],
    ['whitespace string', { value: ' \n  ' }, ' \n  '],
    ['true', { value: true }, 'true'],
    ['false', { value: false }, 'false'],
    ['zero', { value: 0 }, '0'],
    ['positive number', { value: 42 }, '42'],
    ['negative decimal', { value: -1.25 }, '-1.25'],
    ['empty object', { value: {} }, '{}'],
    ['empty array', { value: [] }, '[]'],
    ['nested object', { value: { answer: 42, nested: { enabled: false } } },
      '{\n  "answer": 42,\n  "nested": {\n    "enabled": false\n  }\n}'],
    ['mixed array', { value: ['hello', 0, false, null, { enabled: true }] },
      '[\n  "hello",\n  0,\n  false,\n  null,\n  {\n    "enabled": true\n  }\n]']
  ])('renders %s inside a preformatted code block', (_label, props, expected) => {
    const wrapper = mount(DetailCode, { props })

    expect(wrapper.element.tagName).toBe('PRE')
    expect(wrapper.classes()).toContain('detail-code')
    expect(wrapper.get('pre > code').element.textContent).toBe(expected)
    wrapper.unmount()
  })

  it('renders the defensive undefined fallback when no prop default is applied', () => {
    // Vue normally replaces undefined with the null default before rendering.
    const wrapper = mount({
      ...DetailCode,
      props: {
        ...DetailCode.props,
        value: { ...DetailCode.props.value, default: undefined }
      }
    })

    expect(wrapper.props('value')).toBeUndefined()
    expect(wrapper.get('code').element.textContent).toBe('-')
    wrapper.unmount()
  })

  it.each([
    ['string', '<script>alert("unsafe")</script><b>& text</b>'],
    ['object', { html: '<img src=x onerror="alert(1)">&' }]
  ])('escapes HTML in a %s rather than creating elements', (_label, value) => {
    const wrapper = mount(DetailCode, { props: { value } })
    const code = wrapper.get('code')

    expect(code.element.textContent).toBe(
      typeof value === 'string' ? value : JSON.stringify(value, null, 2)
    )
    expect(code.element.children).toHaveLength(0)
    expect(code.element.innerHTML).toContain('&lt;')
    expect(code.element.innerHTML).toContain('&amp;')
    wrapper.unmount()
  })

  it('updates the rendered value when the prop changes between value types', async () => {
    const wrapper = mount(DetailCode)

    for (const [value, expected] of [
      ['updated', 'updated'],
      [{ answer: 42 }, '{\n  "answer": 42\n}'],
      [[false], '[\n  false\n]'],
      [false, 'false'],
      [0, '0'],
      ['', '-'],
      [null, '-'],
      [undefined, '-']
    ]) {
      await wrapper.setProps({ value })
      expect(wrapper.get('code').element.textContent).toBe(expected)
    }
    wrapper.unmount()
  })
})
