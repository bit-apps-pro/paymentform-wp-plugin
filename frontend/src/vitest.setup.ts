import '@testing-library/jest-dom';
import { vi } from 'vitest';

// Mock window.paymentformAdmin
Object.defineProperty(window, 'paymentformAdmin', {
  value: {
    nonce: 'test-nonce',
    restUrl: 'http://localhost/wp-json/paymentform/v1/',
    adminUrl: 'http://localhost/wp-admin/admin.php?page=paymentform',
    connected: false,
    expiresAt: null,
    version: '0.1.0',
    siteUrl: 'http://localhost',
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
