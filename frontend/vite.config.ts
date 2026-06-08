import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

const WP_EXTERNALS = {
  react: 'React',
  'react-dom': 'ReactDOM',
  '@wordpress/blocks': 'wp.blocks',
  '@wordpress/element': 'wp.element',
  '@wordpress/components': 'wp.components',
  '@wordpress/block-editor': 'wp.blockEditor',
  '@wordpress/i18n': 'wp.i18n',
  '@wordpress/api-fetch': 'wp.apiFetch',
};

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
      external: (id) => Object.keys(WP_EXTERNALS).includes(id),
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: '[name]-[hash].js',
        assetFileNames: '[name][extname]',
        globals: WP_EXTERNALS,
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
