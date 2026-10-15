import { h } from 'vue'
import coreWechatUserConfig from '../../../../../core/crud-admin/src/configs/collections/wechat/WechatUser.js'
import SIP from '@/utils/simple-image-process'
import './wechat-user.css'

const wechatUser = coreWechatUserConfig.WechatUser
const propertyOf = field => typeof field === 'string' ? field : field.property
const listDisplay = wechatUser.list.list_display.filter(field => propertyOf(field) !== 'createdAt')
const openidIndex = listDisplay.indexOf('openid')
if (openidIndex !== -1 && !listDisplay.includes('appId')) {
  listDisplay.splice(openidIndex, 0, 'appId')
}

const avatarColumn = {
  property: 'avatar',
  type: 'image',
  field_options: { className: 'wechat-user-avatar-column' }
}
const remainingListFields = listDisplay.filter(field => !['id', 'avatar', 'nickname'].includes(propertyOf(field)))
const idIndex = listDisplay.findIndex(field => propertyOf(field) === 'id')
listDisplay.splice(0, listDisplay.length, ...[
  ...listDisplay.slice(0, idIndex + 1),
  avatarColumn,
  'nickname',
  ...remainingListFields
])

const AvatarDetail = {
  props: ['data'],
  render() {
    if (!this.data) return h('span', '-')
    const imageUrl = SIP.getPicture(this.data)
    return h('img', {
      src: imageUrl,
      alt: 'WeChat user avatar',
      style: { width: '256px', height: '256px', objectFit: 'cover', borderRadius: '4px' }
    })
  }
}

export default {
  ...wechatUser,
  list: {
    ...wechatUser.list,
    list_display: listDisplay
  },
  detail: {
    ...wechatUser.detail,
    detail_display: wechatUser.detail.detail_display.map(property => property === 'avatarUrl'
      ? { property: 'avatar', component: AvatarDetail }
      : property)
  }
}
