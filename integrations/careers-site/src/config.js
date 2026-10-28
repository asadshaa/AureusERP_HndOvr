/**
 * Site settings, read from environment variables (Vercel → Project →
 * Settings → Environment Variables, or a local shell).
 */
export function config(env = process.env) {
  return {
    siteName: env.SITE_NAME || 'Truck It In',
    siteUrl: (env.SITE_URL || '').replace(/\/+$/, ''),
    demo: env.DEMO_MODE !== 'false',
    allowIndexing: env.ALLOW_INDEXING === 'true',
    username: env.AUREUS_USERNAME || 'aureus-integration',
    appPassword: env.AUREUS_APP_PASSWORD || '',
    // Vercel functions accept request bodies up to 4.5 MB, so the demo keeps CVs under 4 MB.
    maxCvKb: Number.parseInt(env.MAX_CV_KB || '4096', 10),
    privacyUrl: env.PRIVACY_URL || '',
    version: 'demo-1.0.0',
  };
}
