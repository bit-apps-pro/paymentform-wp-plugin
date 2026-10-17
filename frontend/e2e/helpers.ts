import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, type Page, type Response } from '@playwright/test';

const dirname = path.dirname(fileURLToPath(import.meta.url));

/** Live dev WP target; override via env for other installs. */
export const BASE_URL = process.env.WP_BASE_URL ?? 'http://wp-dev.io';
export const WP_USER = process.env.WP_E2E_USER ?? 'pf-e2e';
export const WP_PASS = process.env.WP_E2E_PASS ?? 'pf-e2e-Local1!';

/** storageState produced by auth.setup.ts and reused by every spec. */
export const AUTH_STATE_PATH = path.join(dirname, '.auth/state.json');

export const REST_NAMESPACE = 'paymentform/v1';

/** Pretty-permalink REST path for an endpoint, e.g. restPath('status'). */
export function restPath(endpoint: string): string {
  return `/wp-json/${REST_NAMESPACE}/${endpoint}`;
}

/**
 * Matches a response against a plugin REST endpoint, exact on the route tail
 * so 'forms' never matches 'forms/{id}'. Handles both pretty permalinks
 * (/wp-json/...) and the ?rest_route= fallback.
 */
export function isRestResponse(response: Response, endpoint: string): boolean {
  const url = new URL(response.url());
  const route = `/${REST_NAMESPACE}/${endpoint}`;
  return url.pathname.endsWith(`/wp-json${route}`) || url.searchParams.get('rest_route') === route;
}

/**
 * wp-kit response envelope: every route answers
 * `{ status: 'success'|'error', code, data, message? }`.
 */
export interface Envelope<T = unknown> {
  status: string;
  code: string;
  data: T;
  message?: string;
}

/** Asserts HTTP 200 + success envelope and returns the unwrapped `data`. */
export async function expectSuccessEnvelope<T = unknown>(response: Response): Promise<T> {
  expect(response.status()).toBe(200);
  const body = (await response.json()) as Envelope<T>;
  expect(body.status).toBe('success');
  expect(body.code).toBe('SUCCESS');
  expect(body).toHaveProperty('data');
  return body.data;
}

/** Opens the plugin admin SPA, optionally at a hash route like '#/settings'. */
export async function gotoPluginPage(page: Page, hash = ''): Promise<void> {
  await page.goto(`/wp-admin/admin.php?page=paymentform${hash}`);
  await expect(page.locator('#paymentform-admin-root')).toBeVisible();
}

/** Header connection pill (AntD Tag reading "Connected[ · workspace]" or "Disconnected"). */
export function connectionPill(page: Page) {
  return page.locator('.ant-tag', { hasText: /^(Connected( · .+)?|Disconnected)$/ }).first();
}

/** Fake form returned by mockWorkspace's `/forms` stub when connected. */
export const MOCK_FORM = {
  id: '00000000-0000-4000-8000-000000000001',
  name: 'Donation',
  slug: 'donation',
  status: 'enabled',
  submissionsCount: 0,
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
};

/** `/status` payload mockWorkspace serves when connected. */
export const MOCK_CONNECTED_STATUS = {
  connected: true,
  user: { id: 'u1', email: 'owner@example.test', name: 'E2E Owner' },
  tenant: { id: 't1', name: 'E2E Workspace' },
};

/**
 * Pins the admin SPA's connection state without a live workspace: rewrites the
 * server-rendered config tag and stubs the `/status` and `/forms` REST routes.
 * For layout specs only — contract specs must keep hitting the real backend.
 */
export async function mockWorkspace(page: Page, connected = true): Promise<void> {
  // Rewrite the config tag as the parser inserts it; fulfilling the document via
  // page.route would make Chromium's Private Network Access block its subresources.
  await page.addInitScript(({ tagId, connected }) => {
    new MutationObserver((_records, observer) => {
      const tag = document.getElementById(tagId);
      if (!tag?.textContent) return;
      let config: Record<string, unknown>;
      try {
        config = JSON.parse(tag.textContent) as Record<string, unknown>;
      } catch {
        return; // tag still streaming in; wait for the next mutation
      }
      tag.textContent = JSON.stringify({ ...config, connected, tenant: connected ? 'e2e' : null });
      observer.disconnect();
    }).observe(document, { childList: true, subtree: true, characterData: true });
  }, { tagId: 'wp-script-module-data-@paymentform/admin', connected });
  await stubRest(page, 'status', connected ? MOCK_CONNECTED_STATUS : { connected: false });
  await stubRest(page, 'forms', connected ? [MOCK_FORM] : []);
}

/** Answers a plugin REST GET with a wp-kit success envelope. */
async function stubRest(page: Page, endpoint: string, data: unknown): Promise<void> {
  await page.route(
    (url) => url.pathname.endsWith(`/wp-json/${REST_NAMESPACE}/${endpoint}`) || url.searchParams.get('rest_route') === `/${REST_NAMESPACE}/${endpoint}`,
    (route) => route.fulfill({ json: { status: 'success', code: 'SUCCESS', data } })
  );
}
