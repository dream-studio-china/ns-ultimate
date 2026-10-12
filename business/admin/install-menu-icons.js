import wechatIcon from './icons/wechat.svg?raw'

const svgNamespace = 'http://www.w3.org/2000/svg'

export default function installMenuIcons() {
  if (typeof document === 'undefined' || !document.body || document.getElementById('business-menu-icon-sprite')) {
    return
  }

  const source = new DOMParser().parseFromString(wechatIcon, 'image/svg+xml').documentElement
  const sprite = document.createElementNS(svgNamespace, 'svg')
  sprite.id = 'business-menu-icon-sprite'
  sprite.setAttribute('aria-hidden', 'true')
  sprite.style.position = 'absolute'
  sprite.style.width = '0'
  sprite.style.height = '0'

  const symbol = document.createElementNS(svgNamespace, 'symbol')
  symbol.id = 'icon-wechat'
  symbol.setAttribute('viewBox', source.getAttribute('viewBox') || '0 0 24 24')
  for (const child of source.childNodes) {
    symbol.appendChild(document.importNode(child, true))
  }
  sprite.appendChild(symbol)
  document.body.appendChild(sprite)
}
