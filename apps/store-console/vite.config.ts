import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  base: './',
  plugins: [react(), tailwindcss()],
  server: { host: '127.0.0.1', port: 5180, strictPort: true },
  preview: { host: '127.0.0.1', port: 4180, strictPort: true },
  build: {
    rollupOptions: {
      input: {
        staff: fileURLToPath(new URL('./index.html', import.meta.url)),
        payment: fileURLToPath(new URL('./payment.html', import.meta.url)),
      },
    },
  },
})
