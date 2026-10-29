import { fileURLToPath } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode }) => ({
  base: './',
  plugins: [react(), tailwindcss()],
  server: {
    host: '127.0.0.1', port: 5180, strictPort: true,
    proxy: { '/api': { target: loadEnv(mode, process.cwd(), 'STORE_CONSOLE_').STORE_CONSOLE_API_TARGET || 'http://127.0.0.1:8000' } },
  },
  preview: { host: '127.0.0.1', port: 4180, strictPort: true },
  build: {
    rollupOptions: {
      input: {
        staff: fileURLToPath(new URL('./index.html', import.meta.url)),
        payment: fileURLToPath(new URL('./payment.html', import.meta.url)),
      },
    },
  },
}))
