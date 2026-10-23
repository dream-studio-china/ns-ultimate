// QR rendering is a browser/UI dependency and is never executed here.
export default {
  toDataURL: () => Promise.reject(new Error('QR generation is unavailable during admin manifest export.'))
}
