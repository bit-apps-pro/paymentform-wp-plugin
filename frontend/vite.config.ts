import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

export default defineConfig({
  plugins: [react()],
  build: {
    target: 'ES2022',
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: {
        admin: resolve(__dirname, 'src/main.tsx'),
        'block-editor': resolve(__dirname, 'blocks/form-embed/index.ts'),
        'block-view': resolve(__dirname, 'blocks/form-embed/view.ts'),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name]-[hash].js',
        assetFileNames: '[name][extname]',
        format: 'es',
      },
    },
    minify: false,
    sourcemap: false,
  },
  server: {
    port: 5173,
    open: true,
  },
});
