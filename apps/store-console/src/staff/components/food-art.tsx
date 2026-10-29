const tones: Record<string, string> = { fresh: '#f3ded5', hotpot: '#edddc9', vegetables: '#e4ead9', drinks: '#e6e7d8', snacks: '#eee1c8' }

export function FoodArt({ category, className = '', cover }: { category: string; className?: string; cover?: string }) {
  if (cover) return <div className={`food-art ${className}`} aria-hidden="true"><img className="h-full w-full object-cover" src={cover} alt="" /></div>
  return <div className={`food-art ${className}`} style={{ backgroundColor: tones[category] ?? '#e9e4dc' }} aria-hidden="true">
    <svg viewBox="0 0 100 90" fill="none">
      <ellipse cx="50" cy="73" rx="33" ry="5" fill="#432a1c" opacity=".08" />
      {category === 'drinks' ? <>
        <path d="M34 25H66L61 75H39Z" fill="#f9f6e6" /><path d="M38 38H62L58 70H42Z" fill="#b68d50" />
        <path d="M56 11L51 46" stroke="#526853" strokeWidth="4" /><ellipse cx="50" cy="25" rx="17" ry="4" fill="#fffbed" />
        <path d="M50 54L54 58L50 63L46 58Z" fill="#f4e7bd" />
      </> : <>
        <ellipse cx="50" cy="56" rx="39" ry="23" fill="#fcfaf4" /><ellipse cx="50" cy="55" rx="31" ry="17" fill="#e3d9c8" />
        {category === 'fresh' ? <g stroke="#f8d3c2" strokeWidth="2">{[27, 42, 57].map((x) => <g key={x} transform={`rotate(-18 ${x} 50)`}><rect x={x} y="34" width="17" height="34" rx="7" fill="#b75f50" /><path d={`M${x + 4} 39l9 10-9 6 8 8`} /></g>)}</g>
          : category === 'vegetables' ? <g fill="#829a62"><ellipse cx="39" cy="48" rx="13" ry="20" transform="rotate(-25 39 48)" /><ellipse cx="58" cy="46" rx="13" ry="21" transform="rotate(20 58 46)" /><path d="M45 63L38 37M51 63L61 31" stroke="#cfdbb8" strokeWidth="2" /></g>
          : category === 'hotpot' ? <><path d="M20 44H80L73 68H27Z" fill="#7c5740" /><ellipse cx="50" cy="44" rx="30" ry="12" fill="#cda76d" /><path d="M32 43C40 35 50 53 68 42" stroke="#eee0b9" strokeWidth="4" /><path d="M35 25C29 19 39 18 36 12M54 24C48 18 59 17 56 11" stroke="#fff9ec" strokeWidth="3" strokeLinecap="round" /></>
            : <g fill="#c1914b" stroke="#f0d398" strokeWidth="2"><rect x="26" y="40" width="23" height="17" rx="4" transform="rotate(-15 26 40)" /><rect x="48" y="35" width="23" height="17" rx="4" transform="rotate(16 48 35)" /><rect x="42" y="53" width="23" height="17" rx="4" /></g>}
      </>}
    </svg>
  </div>
}
