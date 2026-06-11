/// <reference types="vite/client" />

/**
 * Dev-only overrides from frontend/.env, exposed through Vite's `envPrefix`
 * (`APP_`). Read via src/lib/devEnv.ts; production builds ignore them and
 * use the PHP-localized window.paymentformAdmin below.
 */
interface ImportMetaEnv {
  readonly APP_API_BASE_URL?: string;
  readonly APP_APP_BASE_URL?: string;
  readonly APP_RENDERER_BASE_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

interface Window {
  paymentformAdmin?: {
    nonce: string;
    restUrl: string;
    adminUrl: string;
    connected: boolean | string;
    expiresAt: string | number | null;
    tenant: string | null;
    version: string;
    siteUrl: string;
    // Optional: present only when the plugin's enqueue hook ran in this context
    // (admin SPA page + block editor). Older PHP or fresh installs without a
    // connected workspace may omit these — consumers must handle absence.
    appBaseUrl?: string;
    rendererBaseUrl?: string;
    // Setup flow and verification state (populated from PHP localization)
    cfCountry?: string;
    adminEmail?: string;
    emailVerified?: boolean | null;
    hasConnection?: boolean;
  };
}
