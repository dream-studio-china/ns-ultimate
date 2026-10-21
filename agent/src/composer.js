export function handleComposerKeydown(event, textarea, submit) {
  if (event.key !== 'Enter' || event.isComposing || event.keyCode === 229) return

  event.preventDefault()
  if (!event.ctrlKey) {
    submit()
    return
  }

  textarea.setRangeText('\n', textarea.selectionStart, textarea.selectionEnd, 'end')
}
