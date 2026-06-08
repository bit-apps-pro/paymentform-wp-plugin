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
  };
}
