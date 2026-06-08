interface Window {
  paymentformAdmin?: {
    nonce: string;
    restUrl: string;
    adminUrl: string;
    connected: boolean;
    expiresAt: number | null;
    version: string;
    siteUrl: string;
  };
}
