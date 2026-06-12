/**
 * Dev-only override from frontend/.env (APP_* vars, exposed via Vite's
 * envPrefix). Returns undefined in production builds so callers fall back
 * to the PHP-injected admin config (see src/utils/wpData.ts).
 */
export function devEnv(key: `APP_${string}`): string | undefined {
  if (!import.meta.env.DEV) return undefined;
  const value = import.meta.env[key] as string | undefined;
  return value || undefined;
}
