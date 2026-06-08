interface Window {
  paymentformAdmin?: {
    nonce: string;
    restUrl: string;
    adminUrl: string;
    connected: boolean | string;
    expiresAt: string | number | null;
    version: string;
    siteUrl: string;
  };
}
