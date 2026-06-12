import { describe, it, expect } from 'vitest';
import { wpModuleData } from '../../utils/wpData';

/**
 * `wpModuleData` is read once at module eval from the Script Module Data JSON
 * tag that `vitest.setup.ts` appends before any test imports run. This asserts
 * the primary (tag-present) parse path resolves the seeded config.
 *
 * The dev/standalone fallback (`isDevFallback`) cannot be exercised here: the
 * helper reads at top-level const, so once evaluated with the tag present it
 * never re-reads in the same process. The fallback is covered by behavior in
 * vitest/raw-Vite contexts where no WP page emits the tag.
 */
describe('wpModuleData', () => {
  it('parses the admin config from the script-module-data tag', () => {
    expect(wpModuleData.restUrl).toBe('http://localhost/wp-json/paymentform/v1/');
    expect(wpModuleData.nonce).toBe('test-nonce');
    expect(wpModuleData.connected).toBe(false);
    expect(wpModuleData.tenant).toBe('test-tenant');
    expect(wpModuleData.version).toBe('0.1.0');
    expect(wpModuleData.siteUrl).toBe('http://localhost');
    expect(wpModuleData.appBaseUrl).toBe('https://app.paymentform.io');
    expect(wpModuleData.rendererBaseUrl).toBe('https://test-tenant.paymentform.io');
  });

  it('does not flag the dev fallback when the data tag is present', () => {
    expect(wpModuleData.isDevFallback).toBeUndefined();
  });
});
