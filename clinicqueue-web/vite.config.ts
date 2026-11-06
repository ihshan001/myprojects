import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Bind to 0.0.0.0 and accept the sandbox preview host so the live preview works
// behind Arena's proxy (https://{port}-{sandboxId}.e2b.app).
export default defineConfig({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: false,
    allowedHosts: true,
    // HMR over the sandbox's https proxy
    hmr: { clientPort: 443, protocol: 'wss' },
  },
})
