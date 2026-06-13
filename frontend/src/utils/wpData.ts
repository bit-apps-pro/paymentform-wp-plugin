/**
 * Admin SPA config delivered by the PHP side via the WP Script Module Data API.
 *
 * The PHP provider registers the admin Script Module under id `@paymentform/admin`
 * and a `script_module_data_@paymentform/admin` filter; WP then emits the config as
 * `<script type="application/json" id="wp-script-module-data-@paymentform/admin">{…}</script>`.
 * Because the admin module is loaded as a deferred `type=module`, that JSON tag is
 * already in the DOM when this module first evaluates — so the read is safe at module
 * scope (no function/memo needed).
 *
 * The type lists ONLY the fields the SPA reads. The server also emits
 * `adminUrl`/`expiresAt`/`hasConnection`, but no SPA code consumes them, so they are
 * omitted here (YAGNI). Add them back only when a real reader appears.
 *
 * The block EDITOR (`blocks/form-embed/edit.tsx`) reads its own editor module's data
 * tag (`@paymentform/block-editor`); the shortcode view falls back to the
 * `window.paymentformView` classic global since the shortcode path is not a Script
 * Module. Both are independent of this admin config.
 */
export type PaymentformAdminConfig = {
  nonce: string
  restUrl: string
  connected: boolean | string
  tenant: string | null
  version: string
  siteUrl: string
  appBaseUrl?: string
  rendererBaseUrl?: string
  cfCountry?: string
  adminEmail?: string
  emailVerified?: boolean | null
  // Set on the dev/standalone fallback below (no WP page emitting the data tag).
  isDevFallback?: boolean
}

const MODULE_ID = '@paymentform/admin' // MUST equal the PHP wp_register_script_module id
const container = document.getElementById(`wp-script-module-data-${MODULE_ID}`)

/**
 * Parsed admin config, read once at module evaluation. Falls back to a minimal
 * dev/standalone shape when the data tag is absent (vitest, raw Vite without a WP
 * page) — `isDevFallback` flags that case. `request()` guards on `restUrl`, so a
 * blank fallback fails loudly in any real REST context rather than silently posting
 * to the wrong host.
 */
export const wpModuleData: PaymentformAdminConfig = container
  ? (JSON.parse(container.textContent ?? '{}') as PaymentformAdminConfig)
  : {
      restUrl: '/wp-json/paymentform/v1/',
      nonce: '',
      connected: false,
      tenant: null,
      version: '',
      siteUrl: '',
      isDevFallback: true,
    }

if (import.meta.env.DEV) {
  // Dev-only visibility into the resolved config; never shipped to prod builds.
  console.debug('paymentform wpModuleData', wpModuleData)
}
