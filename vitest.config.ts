import { configDefaults, defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: 'happy-dom',
    setupFiles: ['./frontend/src/vitest.setup.ts'],
    // Playwright specs live in frontend/e2e/ and must never run under vitest.
    exclude: [...configDefaults.exclude, 'frontend/e2e/**'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
    },
  },
});
