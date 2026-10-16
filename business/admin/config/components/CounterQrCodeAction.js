import { defineComponent, h, ref, resolveComponent } from 'vue'
import { t } from '@/i18n'
import './CounterQrCodeAction.css'

const ORDER_H5_URL = 'https://ns-ultimate.wscoffee.com.cn/h5/order/index.html'
const QR_IMAGE_URL = 'https://api.qrserver.com/v1/create-qr-code/'

function buildOrderUrl(counterUuid) {
  const url = new URL(ORDER_H5_URL)
  url.searchParams.set('counterId', counterUuid)
  return url.toString()
}

function buildQrImageUrl(value) {
  const url = new URL(QR_IMAGE_URL)
  url.searchParams.set('size', '320x320')
  url.searchParams.set('format', 'png')
  url.searchParams.set('data', value)
  return url.toString()
}

function safeFilePart(value) {
  return String(value || 'counter').replace(/[\\/:*?"<>|\s]+/g, '-').replace(/^-+|-+$/g, '') || 'counter'
}

export default defineComponent({
  name: 'CounterQrCodeAction',
  props: {
    record: { type: Object, default: () => ({}) }
  },
  setup(props) {
    const ElButton = resolveComponent('ElButton')
    const ElDialog = resolveComponent('ElDialog')
    const visible = ref(false)
    const qrImageUrl = ref('')
    const orderUrl = ref('')
    const errorMessage = ref('')
    const copyMessage = ref('')

    const open = () => {
      const counterUuid = props.record?.uuid
      if (!counterUuid) return

      orderUrl.value = buildOrderUrl(counterUuid)
      qrImageUrl.value = buildQrImageUrl(orderUrl.value)
      visible.value = true
      errorMessage.value = ''
      copyMessage.value = ''
    }

    const copyUrl = async() => {
      try {
        await navigator.clipboard.writeText(orderUrl.value)
        copyMessage.value = t('Counter QR link copied')
      } catch (error) {
        window.prompt(t('Copy counter QR link manually'), orderUrl.value)
      }
    }

    const download = async() => {
      if (!qrImageUrl.value) return
      const anchor = document.createElement('a')
      anchor.download = `${safeFilePart(props.record?.name || props.record?.code || props.record?.uuid)}-order-qr.png`
      try {
        const response = await fetch(qrImageUrl.value)
        if (!response.ok) throw new Error('QR image request failed')
        const objectUrl = URL.createObjectURL(await response.blob())
        anchor.href = objectUrl
        anchor.click()
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
      } catch (error) {
        window.open(qrImageUrl.value, '_blank', 'noopener,noreferrer')
      }
    }

    return () => h('span', [
      h(ElButton, {
        size: 'small',
        plain: true,
        icon: 'el-icon-full-screen',
        disabled: !props.record?.uuid,
        onClick: (event) => {
          event?.stopPropagation?.()
          open()
        }
      }, () => t('Counter QR code')),
      h(ElDialog, {
        modelValue: visible.value,
        'onUpdate:modelValue': (value) => { visible.value = value },
        title: `${t('Counter ordering QR code')} - ${props.record?.name || props.record?.code || ''}`,
        width: '440px',
        alignCenter: true,
        appendToBody: true,
        closeOnClickModal: false
      }, {
        default: () => h('div', { class: 'counter-qr-dialog' }, [
          h('p', { class: 'counter-qr-dialog__hint' }, t('Scan to order at this counter')),
          qrImageUrl.value && !errorMessage.value
            ? h('img', {
              class: 'counter-qr-dialog__image',
              src: qrImageUrl.value,
              alt: t('Counter ordering QR code'),
              onError: () => { errorMessage.value = t('Counter QR code generation failed') }
            })
            : h('div', { class: 'counter-qr-dialog__placeholder' }, errorMessage.value || t('Counter QR code generation failed')),
          h('div', { class: 'counter-qr-dialog__url', title: orderUrl.value }, orderUrl.value),
          h('p', { class: 'counter-qr-dialog__uuid' }, `${t('Counter UUID')}: ${props.record?.uuid || ''}`),
          copyMessage.value && h('p', { class: 'counter-qr-dialog__copied' }, copyMessage.value)
        ]),
        footer: () => h('div', { class: 'counter-qr-dialog__footer' }, [
          h(ElButton, { onClick: copyUrl }, () => t('Copy link')),
          h(ElButton, { type: 'primary', disabled: !qrImageUrl.value || !!errorMessage.value, onClick: download }, () => t('Download QR code'))
        ])
      })
    ])
  }
})
