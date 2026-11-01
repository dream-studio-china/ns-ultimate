<template>
  <section class="appearance-settings" aria-labelledby="appearance-title">
    <header class="appearance-header">
      <div>
        <span class="appearance-eyebrow">{{ $t('Appearance personalization') }}</span>
        <h1 id="appearance-title">{{ $t('Appearance settings') }}</h1>
        <p>{{ $t('Appearance palette help') }}</p>
      </div>
      <button type="button" class="reset-button" :disabled="selected === 'default'" @click="resetPalette">
        {{ $t('Restore original palette') }}
      </button>
    </header>

    <div class="appearance-layout">
      <fieldset class="palette-panel">
        <legend>{{ $t('Color palette') }}</legend>
        <p class="panel-description">{{ $t('Palette selection help') }}</p>
        <div class="palette-options">
          <label v-for="palette in palettes" :key="palette.value" class="palette-option" :class="{ 'is-selected': selected === palette.value }" :style="{ '--swatch-color': palette.value === 'default' ? 'var(--text-secondary)' : palette.color }">
            <input v-model="selected" type="radio" name="admin-palette" :value="palette.value" @change="selectPalette">
            <span class="palette-art" aria-hidden="true">
              <span class="palette-orbit" />
              <span class="palette-check">{{ selected === palette.value ? '✓' : '' }}</span>
            </span>
            <span class="palette-label">{{ $t(palette.label) }}</span>
            <span class="palette-code">{{ palette.value === 'default' ? $t('Follow base theme') : palette.color.toUpperCase() }}</span>
          </label>
        </div>
      </fieldset>

      <aside class="preview-panel" :style="{ '--preview-color': currentPalette.value === 'default' ? 'var(--accent)' : currentPalette.color }" :aria-label="$t('Palette preview')">
        <div class="preview-heading">
          <h2>{{ $t('Palette preview') }}</h2>
          <span class="preview-badge">{{ $t('Live preview') }}</span>
        </div>
        <div class="workspace-preview" aria-hidden="true">
          <div class="preview-sidebar">
            <span class="preview-logo" />
            <span class="preview-nav is-active" /><span class="preview-nav" /><span class="preview-nav" />
          </div>
          <div class="preview-workspace">
            <div class="preview-topbar"><span /><i /></div>
            <div class="preview-banner"><span /><span /></div>
            <div class="preview-metrics"><span /><span /><span /></div>
            <div class="preview-table"><span /><span /><span /></div>
          </div>
        </div>
        <div class="current-palette" role="status" aria-live="polite">
          <span class="current-swatch" />
          <div><span class="current-caption">{{ $t('Current palette') }}</span><strong>{{ $t(currentPalette.label) }}</strong></div>
          <span class="current-check" aria-hidden="true">✓</span>
        </div>
        <p class="preview-help">{{ $t('Appearance storage help') }}</p>
      </aside>
    </div>
  </section>
</template>

<script>
import { applyPalette, palettes } from './palette.mjs'

export default {
  name: 'AppearanceSettings',
  data() {
    return { palettes, selected: document.documentElement.dataset.adminPalette || 'default' }
  },
  computed: {
    currentPalette() {
      return this.palettes.find(palette => palette.value === this.selected) || this.palettes[0]
    }
  },
  methods: {
    selectPalette() {
      this.selected = applyPalette(this.selected)
    },
    resetPalette() {
      this.selected = applyPalette('default')
    }
  }
}
</script>

<style scoped>
.appearance-settings { max-width: 1440px; margin: 0 auto; padding: 32px; color: var(--text-primary); }
.appearance-header { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 28px; }
.appearance-eyebrow { color: var(--accent); font-size: 12px; font-weight: 700; letter-spacing: .08em; }
h1 { margin: 10px 0; font-size: 28px; letter-spacing: -.03em; }
p { color: var(--text-secondary); font-size: 13px; line-height: 1.8; }
.appearance-header p { max-width: 680px; margin-bottom: 0; }
.reset-button { flex-shrink: 0; padding: 10px 16px; border: 1px solid var(--border); border-radius: 9px; color: var(--text-primary); background: var(--surface); font: inherit; font-size: 13px; cursor: pointer; }
.reset-button:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
.reset-button:disabled { opacity: .45; cursor: default; }
.reset-button:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.appearance-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 24px; align-items: start; }
.palette-panel, .preview-panel { min-width: 0; margin: 0; padding: 24px; border: 1px solid var(--border); border-radius: 16px; background: var(--surface); }
legend { padding: 0 8px; font-size: 16px; font-weight: 650; }
.panel-description { margin: 0 0 20px; }
.palette-options { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.palette-option { position: relative; display: flex; flex-direction: column; min-width: 0; gap: 7px; padding: 10px 10px 14px; border: 2px solid var(--border); border-radius: 12px; background: var(--surface); cursor: pointer; transition: border-color .15s, transform .15s; }
.palette-option:hover { transform: translateY(-2px); border-color: var(--swatch-color); }
.palette-option.is-selected { border-color: var(--accent); }
.palette-option:focus-within { outline: 2px solid var(--accent); outline-offset: 3px; }
.palette-option input { position: absolute; width: 1px; height: 1px; opacity: 0; }
.palette-art { position: relative; display: grid; place-items: center; height: 82px; margin-bottom: 4px; overflow: hidden; border-radius: 7px; background: color-mix(in srgb, var(--swatch-color) 9%, var(--surface)); }
.palette-orbit { width: 44px; height: 44px; border: 9px solid var(--swatch-color); border-radius: 50%; box-shadow: 18px 12px 0 -6px color-mix(in srgb, var(--swatch-color) 35%, var(--surface)); }
.palette-check { position: absolute; top: 8px; right: 8px; display: grid; place-items: center; width: 20px; height: 20px; border-radius: 50%; color: #fff; font-size: 12px; }
.is-selected .palette-check { background: var(--swatch-color); }
.palette-label { padding: 0 4px; font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
.palette-code { padding: 0 4px; color: var(--text-secondary); font-size: 11px; font-weight: 400; letter-spacing: .03em; }
.preview-panel { position: sticky; top: 24px; }
.preview-heading { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 20px; }
h2 { margin: 0; font-size: 15px; }
.preview-badge { padding: 5px 8px; border-radius: 6px; color: var(--accent); background: var(--surface-muted); font-size: 10px; }
.workspace-preview { display: flex; height: 196px; overflow: hidden; border: 1px solid var(--border); border-radius: 10px; background: var(--app-bg); }
.preview-sidebar { display: flex; flex-direction: column; gap: 10px; width: 46px; padding: 14px 8px; background: #182230; }
.preview-logo { width: 16px; height: 16px; margin: 0 auto 10px; border: 4px solid var(--preview-color); border-radius: 5px; }
.preview-nav { height: 7px; border-radius: 3px; background: #475467; }
.preview-nav.is-active { height: 15px; background: var(--preview-color); }
.preview-workspace { flex: 1; min-width: 0; padding: 12px; }
.preview-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
.preview-topbar span { width: 50px; height: 5px; border-radius: 3px; background: var(--border); }
.preview-topbar i { width: 12px; height: 12px; border-radius: 50%; background: var(--preview-color); }
.preview-banner { display: grid; gap: 7px; padding: 12px; border-radius: 6px; background: var(--preview-color); }
.preview-banner span { width: 50%; height: 5px; border-radius: 3px; background: rgba(255, 255, 255, .8); }
.preview-banner span:last-child { width: 75%; opacity: .5; }
.preview-metrics { display: flex; gap: 6px; margin: 10px 0; }
.preview-metrics span { flex: 1; height: 25px; border: 1px solid var(--border); border-top: 3px solid var(--preview-color); border-radius: 4px; background: var(--surface); }
.preview-table { display: grid; gap: 7px; padding: 9px; border-radius: 5px; background: var(--surface); }
.preview-table span { height: 3px; border-radius: 2px; background: var(--border); }
.current-palette { display: flex; align-items: center; gap: 12px; margin-top: 22px; }
.current-swatch { flex-shrink: 0; width: 34px; height: 34px; border: 5px solid var(--surface-muted); border-radius: 50%; background: var(--preview-color); }
.current-caption { display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 11px; }
.current-palette strong { font-size: 14px; }
.current-check { margin-left: auto; color: var(--accent); }
.preview-help { margin: 20px 0 0; padding-top: 16px; border-top: 1px solid var(--border); font-size: 12px; }
@media (max-width: 1200px) { .appearance-layout { grid-template-columns: minmax(0, 1fr) 280px; } .palette-options { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 900px) { .appearance-layout { grid-template-columns: minmax(0, 1fr); } .preview-panel { position: static; } .palette-options { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 600px) { .appearance-settings { padding: 20px 14px; } .appearance-header { align-items: flex-start; flex-direction: column; gap: 16px; } h1 { font-size: 24px; } .palette-panel, .preview-panel { padding: 16px; } .palette-options { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } }
@media (prefers-reduced-motion: reduce) { .palette-option { transition: none; } .palette-option:hover { transform: none; } }
</style>
