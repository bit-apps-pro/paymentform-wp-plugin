import '@testing-library/jest-dom';
import { vi } from 'vitest';

// Flag for production modules whose auto-boot at import time would leak across
// test cases (see blocks/form-embed/view.ts).
(globalThis as unknown as { __VITEST__?: boolean }).__VITEST__ = true;

// Mock the admin Script Module Data JSON tag that src/utils/wpData.ts reads at
// module eval. This setup file runs before any test module imports the helper,
// so the tag is in the DOM when `wpModuleData` is first evaluated.
const adminConfig = {
  nonce: 'test-nonce',
  restUrl: 'http://localhost/wp-json/paymentform/v1/',
  connected: false,
  tenant: 'test-tenant',
  version: '0.1.0',
  siteUrl: 'http://localhost',
  appBaseUrl: 'https://app.paymentform.io',
  rendererBaseUrl: 'https://test-tenant.paymentform.io',
};
const adminConfigTag = document.createElement('script');
adminConfigTag.type = 'application/json';
adminConfigTag.id = 'wp-script-module-data-@paymentform/admin';
adminConfigTag.textContent = JSON.stringify(adminConfig);
document.head.appendChild(adminConfigTag);

// The block EDITOR (blocks/form-embed/edit.tsx) now ships as a Script Module too
// and reads its config from a Script Module Data JSON tag, same as the SPA. Seed
// the editor tag so edit.tsx resolves nonce / renderer / app config in tests.
const editorConfigTag = document.createElement('script');
editorConfigTag.type = 'application/json';
editorConfigTag.id = 'wp-script-module-data-@paymentform/block-editor';
editorConfigTag.textContent = JSON.stringify({
  nonce: 'test-nonce',
  rendererBaseUrl: 'https://test-tenant.paymentform.io',
  appBaseUrl: 'https://app.paymentform.io',
});
document.head.appendChild(editorConfigTag);

// Mock fetch globally
globalThis.fetch = vi.fn() as unknown as typeof fetch;

// Mock navigator.clipboard
Object.defineProperty(navigator, 'clipboard', {
  value: {
    writeText: vi.fn(() => Promise.resolve()),
  },
  writable: true,
});
