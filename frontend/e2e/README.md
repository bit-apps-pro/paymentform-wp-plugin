# E2E suite (Playwright)

Browser tests for the wp-kit REST migration: admin SPA, embed drawer, settings,
REST auth contract (401 `INVALID_NONCE`), and the Gutenberg block. They run
against a **live dev WordPress install** — not a fixture.

## Prerequisites

- A reachable WP install with the plugin active, connected to a workspace that
  has one form named **Donation** (the default target `http://wp-dev.io` is
  already provisioned like this).
- An administrator account holding `paymentform_manage`.

## Run

Run from the plugin root (where `package.json` and `playwright.config.ts` now live):

```sh
pnpm exec playwright install chromium   # once
pnpm test:e2e
```

## Environment

| Variable      | Default            | Purpose                  |
| ------------- | ------------------ | ------------------------ |
| `WP_BASE_URL` | `http://wp-dev.io` | Target WordPress install |
| `WP_E2E_USER` | `pf-e2e`           | wp-login.php username    |
| `WP_E2E_PASS` | `pf-e2e-Local1!`   | wp-login.php password    |

The defaults are dev-only local credentials. Auth happens once in
`auth.setup.ts`; cookies persist to `e2e/.auth/state.json` (gitignored) and are
reused by every spec.

## Non-destructive policy

The dev site stays **connected**. No spec may click Disconnect or Authorize, or
otherwise mutate the connection. `wizard-diagnose.spec.ts` covers a
disconnected-only flow and self-skips while the header pill reads "Connected".
The block-editor spec inserts a block but never saves the post; the
`/disconnect` auth test asserts a 401, which proves the handler never ran.
