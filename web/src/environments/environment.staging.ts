/**
 * Staging build, served at new.freightmove.au.
 *
 * Same API as production — there is one API subdomain — but `siteUrl` points at
 * the staging host so canonical and Open Graph URLs describe the page you are
 * actually on.
 *
 * **Staging must never be indexed.** A full copy of the site on a public
 * subdomain is duplicate content that can outrank or cannibalise the real one,
 * and it would put customers on a test build. `npm run build:staging` writes a
 * `Disallow: /` robots.txt for exactly this reason — see
 * scripts/generate-sitemap.mjs.
 */
export const environment = {
  production: true,
  apiUrl: 'https://api.freightmove.au/api/v1',
  siteUrl: 'https://new.freightmove.au',

  /**
   * Google Places, used by the pickup and dropoff fields.
   *
   * Left empty in the repository on purpose. A Maps key has to reach the
   * browser to work, so it cannot be kept secret — but it can be kept useless
   * to anyone else, and that is the control that matters:
   *
   *   1. Application restriction  → HTTP referrers, listing only this site's
   *      origins. Without it, a key lifted from the bundle bills to you.
   *   2. API restriction          → Places API only.
   *   3. A budget alert on the project.
   *
   * Paste the restricted key here before building. Empty is a supported state:
   * the fields fall back to plain text inputs and the form still works.
   *
   * The legacy site hardcoded its key inside a controller (docs/11-security.md);
   * that key still needs rotating and must not be reused here.
   */
  googleMapsApiKey: '',
};
