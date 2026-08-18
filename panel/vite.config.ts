import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

// The panel is served as static files by the same Apache that serves the
// API (FR-2.7), so the built assets use relative paths and the dev server
// proxies /api to avoid CORS during development.
//
// The API origin is 127.0.0.1:8080 as in the README. Set API_ORIGIN in
// panel/.env.local to point somewhere else — needed when another service
// on the machine already holds 8080, which is a port plenty of things
// want.
export default defineConfig(({ mode }) => ({
  plugins: [react()],
  base: './',
  server: {
    port: 5173,
    // Reachable from other devices on the LAN, not just this machine —
    // admins test the panel from their own laptops. /api requests from
    // those devices flow through this dev proxy, so they need no
    // direct route to the API port.
    host: true,
    proxy: {
      '/api': {
        target: loadEnv(mode, process.cwd(), 'API_').API_ORIGIN ?? 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
  },
}));
