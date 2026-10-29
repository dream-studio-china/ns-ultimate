import type { Illustration } from '../domain/catalog'
import { cn } from '../lib/utils'

const tones: Record<Illustration, string> = { black: '#ece8df', latte: '#eedfd1', flat: '#e5e8df', foam: '#eee6db', oat: '#e9dfce', matcha: '#dfe7d5', lemon: '#f1eaca', peach: '#f0ded8', croissant: '#eee1cc', bagel: '#e9dfd1', cake: '#ede4d7', brownie: '#e8dcd6' }
export function ProductArt({ kind, className }: { kind: Illustration; className?: string }) {
  const food = ['croissant', 'bagel', 'cake', 'brownie'].includes(kind)
  return <div className={cn('product-art relative overflow-hidden', className)} style={{ backgroundColor: tones[kind] }} aria-hidden="true">
    <svg viewBox="0 0 240 160" className="h-full w-full" fill="none">
      <ellipse cx="124" cy="137" rx={food ? 69 : 52} ry="9" fill="#453421" opacity=".09" />
      {food ? <>
        <ellipse cx="121" cy="119" rx="84" ry="22" fill="#fffaf0" /><ellipse cx="121" cy="116" rx="70" ry="16" stroke="#ded5c5" />
        {kind === 'croissant' && <g transform="rotate(-12 120 90)"><path d="M51 107C39 76 64 50 87 63C105 42 139 43 153 65C179 54 200 81 187 107L160 92C139 106 101 104 82 91Z" fill="#c98a40" /><path d="M85 65L78 91M108 54L103 101M132 53L139 101M153 66L165 93" stroke="#f2c16c" strokeWidth="14" strokeLinecap="round" /><path d="M53 101L73 84M187 102L167 83" stroke="#e3a34e" strokeWidth="10" strokeLinecap="round" /></g>}
        {kind === 'bagel' && <><ellipse cx="120" cy="90" rx="64" ry="39" fill="#b97835" /><ellipse cx="120" cy="83" rx="64" ry="37" fill="#d7a363" /><ellipse cx="120" cy="80" rx="19" ry="11" fill="#9b6738" /><ellipse cx="120" cy="85" rx="13" ry="6" fill="#eee1cc" />{[67, 86, 100, 145, 159, 172, 82, 150].map((x, i) => <path key={i} d={`M${x} ${70 + i % 3 * 13}l4 -2`} stroke="#f5dfb8" strokeWidth="3" strokeLinecap="round" />)}</>}
        {kind === 'cake' && <><path d="M70 73L146 48L176 96L107 121L70 105Z" fill="#e5c282" /><path d="M70 73L146 48L176 82L107 106Z" fill="#80502d" /><path d="M107 106L176 82V101L107 125Z" fill="#f6dca4" /><path d="M70 73L107 106V125L70 105Z" fill="#edcf96" /><path d="M81 72L145 52L166 78L107 98Z" fill="#a06536" /></>}
        {kind === 'brownie' && <><path d="M66 76L134 54L178 77V111L107 133L66 107Z" fill="#4e2f24" /><path d="M66 76L134 54L178 77L107 101Z" fill="#6b4430" /><path d="M80 76L99 81M114 67L129 73M141 80L158 85M104 90L119 86" stroke="#a27752" strokeWidth="4" strokeLinecap="round" /></>}
      </> : <>
        <ellipse cx="117" cy="128" rx="61" ry="12" fill="#faf7ef" />
        <path d="M156 69H165C192 69 189 110 158 109" stroke="#fdfaf4" strokeWidth="12" />
        <path d="M69 62H165L156 112C152 137 82 137 78 112Z" fill={kind === 'matcha' ? '#f3f1e6' : '#fffdf6'} />
        <path d="M76 74L83 111C86 123 96 127 108 128" stroke="#ddd6c6" strokeWidth="3" opacity=".6" />
        <ellipse cx="117" cy="62" rx="48" ry="15" fill="#e4ddce" />
        <ellipse cx="117" cy="62" rx="43" ry="12" fill={kind === 'black' ? '#503527' : kind === 'matcha' ? '#829b56' : kind === 'lemon' ? '#c99132' : kind === 'peach' ? '#c78959' : '#bc8757'} />
        {!['black', 'lemon', 'peach'].includes(kind) && <g stroke="#fff3d9" strokeWidth="3" strokeLinecap="round"><path d="M117 70C86 61 102 50 117 60C132 48 150 61 117 70Z" fill="#f7ead0" /><path d="M117 68L123 54" /></g>}
        {kind === 'lemon' && <><ellipse cx="126" cy="60" rx="14" ry="8" fill="#ead371" stroke="#fbefab" strokeWidth="3" /><path d="M112 58L140 62M124 52L128 68" stroke="#fbefab" strokeWidth="2" /></>}
        <path d="M104 35C94 27 110 25 104 16M126 32C116 24 132 22 126 13" stroke="#ffffff" strokeWidth="3" strokeLinecap="round" opacity=".65" />
        <path d="M105 97C105 88 125 88 125 97C125 106 105 106 105 97Z" stroke="#44604d" strokeWidth="1.5" opacity=".65" /><path d="M115 91V103M109 95L115 99L121 95" stroke="#44604d" strokeWidth="1.5" opacity=".65" />
      </>}
    </svg>
  </div>
}
