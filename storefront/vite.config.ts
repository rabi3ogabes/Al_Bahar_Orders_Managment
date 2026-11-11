import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Built into /store (served by Laravel/nginx); the shell is returned by Laravel for every storefront URL.
export default defineConfig({
  base: '/store/',
  plugins: [react(), tailwindcss()],
  build: { target: 'es2020', sourcemap: false, chunkSizeWarningLimit: 700 },
  server: { port: 5173, proxy: { '/api': 'http://127.0.0.1:8000', '/storage': 'http://127.0.0.1:8000' } },
});
