import wechatIcon from './icons/wechat.svg?raw'
import counterIcon from './icons/counter.svg?raw'

const svgNamespace = 'http://www.w3.org/2000/svg'

export default function installMenuIcons() {
  if (typeof document === 'undefined' || !document.body || document.getElementById('business-menu-icon-sprite')) {
    return
  }

  const icons = [
    ['wechat', wechatIcon],
    ['counter', counterIcon]
  ]
  const sprite = document.createElementNS(svgNamespace, 'svg')
  sprite.id = 'business-menu-icon-sprite'
  sprite.setAttribute('aria-hidden', 'true')
  sprite.style.position = 'absolute'
  sprite.style.width = '0'
  sprite.style.height = '0'

  for (const [name, source] of icons) {
    const svg = new DOMParser().parseFromString(source, 'image/svg+xml').documentElement
    const symbol = document.createElementNS(svgNamespace, 'symbol')
    symbol.id = `icon-${name}`
    symbol.setAttribute('viewBox', svg.getAttribute('viewBox') || '0 0 24 24')
    for (const child of svg.childNodes) {
      symbol.appendChild(document.importNode(child, true))
    }
    sprite.appendChild(symbol)
  }
  document.body.appendChild(sprite)
}
