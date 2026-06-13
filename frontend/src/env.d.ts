/// <reference types="vite/client" />

/**
 * Dev-only overrides from frontend/.env, exposed through Vite's `envPrefix`
 * (`APP_`). Read via src/lib/devEnv.ts; production builds ignore them and read
 * the admin config from the Script Module Data tag (see src/utils/wpData.ts).
 */
interface ImportMetaEnv {
  readonly APP_API_BASE_URL?: string;
  readonly APP_APP_BASE_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
