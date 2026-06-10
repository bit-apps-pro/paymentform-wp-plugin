import '@testing-library/jest-dom';
import { vi } from 'vitest';

// Flag for production modules whose auto-boot at import time would leak across
// test cases (see blocks/form-embed/view.ts).
(globalThis as unknown as { __VITEST__?: boolean }).__VITEST__ = true;

// Mock window.paymentformAdmin
Object.defineProperty(window, 'paymentformAdmin', {
  value: {
    nonce: 'test-nonce',
    restUrl: 'http://localhost/wp-json/paymentform/v1/',
    adminUrl: 'http://localhost/wp-admin/admin.php?page=paymentform',
    connected: false,
    expiresAt: null,
    tenant: 'test-tenant',
    version: '0.1.0',
    siteUrl: 'http://localhost',
    appBaseUrl: 'https://app.paymentform.io',
    rendererBaseUrl: 'https://test-tenant.paymentform.io',
  },
  writable: true,
});

// Mock fetch globally
(globalThis as any).fetch = vi.fn();

// Mock navigator.clipboard
Object.defineProperty(navigator, 'clipboard', {
  value: {
    writeText: vi.fn(() => Promise.resolve()),
  },
  writable: true,
});
