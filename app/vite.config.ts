import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';

const moduleBase = process.env.KREAFLOW_BASE || '/custom/kreaflow/frontend/';
const moduleRoot = moduleBase.replace(/frontend\/$/, '');

const pwaPlugins = VitePWA({
      registerType: 'autoUpdate',
      filename: '../sw.js',
      scope: moduleRoot,
      includeAssets: ['kreaflow-favicon-v4.svg', 'kreaflow-apple-touch-v3.png', 'kreaflow-icon-192-v3.png', 'kreaflow-icon-512-v3.png', 'kreaflow-maskable-512-v3.png'],
      manifest: {
        name: 'KreaFlow Mobile',
        short_name: 'KreaFlow',
        description: 'Mobile-first Dolibarr frontend for KreaFlow workflows.',
        theme_color: '#e63d00',
        background_color: '#f7f8fa',
        display: 'standalone',
        // Preserve the identity of previously installed apps.
        id: moduleBase,
        scope: moduleRoot,
        start_url: moduleRoot + 'mobile.php',
        prefer_related_applications: false,
        icons: [
          {
            src: 'kreaflow-icon-192-v3.png',
            sizes: '192x192',
            type: 'image/png'
          },
          {
            src: 'kreaflow-icon-512-v3.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any'
          },
          {
            src: 'kreaflow-maskable-512-v3.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable'
          }
        ]
      },
      workbox: {
        skipWaiting: true,
        clientsClaim: true,
        cleanupOutdatedCaches: true,
        globPatterns: ['**/*.{js,css,html,svg,png,ico}'],
        // Cache static assets only; authenticated PHP/API responses stay network-only.
        modifyURLPrefix: { '': moduleBase },
        navigateFallback: moduleBase + 'index.html',
        navigateFallbackAllowlist: [new RegExp('^' + moduleBase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))]
      }
    });

export default defineConfig({
  base: moduleBase,
  plugins: [
    react(),
    ...pwaPlugins,
    {
      name: 'kreaflow-root-worker-assets',
      buildStart() {
        // VitePWA appends icons/manifest after Workbox URL transforms.
        pwaPlugins.find((plugin) => plugin.name === 'vite-plugin-pwa')?.api.extendManifestEntries((entries: Array<string | { url: string; revision?: string | null }>) =>
          entries.map((entry) => typeof entry === 'string'
            ? (entry.startsWith('/') ? entry : moduleBase + entry)
            : { ...entry, url: entry.url.startsWith('/') ? entry.url : moduleBase + entry.url })
        );
      }
    }
  ],
  build: {
    sourcemap: false,
    target: 'es2020'
  }
});
