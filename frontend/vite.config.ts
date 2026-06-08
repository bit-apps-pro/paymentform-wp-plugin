import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    target: 'ES2022',
    outDir: 'dist',
    lib: {
      entry: 'src/main.tsx',
      formats: ['iife'],
      name: 'PaymentFormAdmin',
      fileName: () => 'admin.js',
    },
    rollupOptions: {
      output: {
        entryFileNames: 'admin.js',
        assetFileNames: 'admin.[ext]',
        inlineDynamicImports: true,
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
