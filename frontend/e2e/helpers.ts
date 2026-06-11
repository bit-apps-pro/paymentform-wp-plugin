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

/** Header connection pill (AntD Tag rendering exactly Connected/Disconnected). */
export function connectionPill(page: Page) {
  return page.locator('.ant-tag', { hasText: /^(Connected|Disconnected)$/ }).first();
}
