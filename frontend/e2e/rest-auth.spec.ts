import {
  test,
  expect,
  request as playwrightRequest,
  type APIRequestContext,
  type APIResponse,
} from '@playwright/test';
import { AUTH_STATE_PATH, BASE_URL, restPath, type Envelope } from './helpers';

/**
 * REST auth contract. Routes are registered with
 * `permission_callback => '__return_true'`, so the wp-kit middleware chain
 * (nonce first, then capability) is the ONLY auth layer — any request without
 * a valid X-WP-Nonce must die with 401 INVALID_NONCE before the controller
 * (or even input validation) runs. Pure request-context tests: no page, no
 * project storageState.
 */
async function expectInvalidNonce(res: APIResponse): Promise<void> {
  expect(res.status()).toBe(401);
  const body = (await res.json()) as Envelope;
  expect(body.status).toBe('error');
  expect(body.code).toBe('INVALID_NONCE');
}

test.describe('unauthenticated requests', () => {
  let anon: APIRequestContext;

  test.beforeAll(async () => {
    anon = await playwrightRequest.newContext({ baseURL: BASE_URL });
  });

  test.afterAll(async () => {
    await anon.dispose();
  });

  test('GET /status answers 401 INVALID_NONCE', async () => {
    await expectInvalidNonce(await anon.get(restPath('status')));
  });

  test('POST /disconnect answers 401 INVALID_NONCE', async () => {
    // Safe on the connected dev site: the 401 proves disconnect never runs.
    await expectInvalidNonce(await anon.post(restPath('disconnect')));
  });

  test('GET /forms/abc is rejected by auth before id validation', async () => {
    // Auth middleware must fire before the controller's numeric-id check
    // (which would answer 400 INVALID_FORM_ID).
    await expectInvalidNonce(await anon.get(restPath('forms/abc')));
  });
});

test('authenticated cookies without X-WP-Nonce still get 401 INVALID_NONCE', async () => {
  // Logged-in session cookies alone must not pass: the nonce middleware runs
  // before the capability check and rejects nonce-less requests outright.
  const authed = await playwrightRequest.newContext({
    baseURL: BASE_URL,
    storageState: AUTH_STATE_PATH,
  });
  try {
    await expectInvalidNonce(await authed.get(restPath('status')));
  } finally {
    await authed.dispose();
  }
});
