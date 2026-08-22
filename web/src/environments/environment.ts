/**
 * Production build.
 *
 * The API lives on its own subdomain, so `apiUrl` is absolute and every request
 * is cross-origin — which means `FRONTEND_URL` on the API must list this
 * origin, or the browser blocks the response before the app ever sees it.
 * Authentication is bearer-token, not cookie, so there is no SameSite or
 * stateful-domain configuration to get wrong beyond CORS.
 */
export const environment = {
  production: true,
  apiUrl: 'https://api.freightmove.au/api/v1',
  /** Public origin, used to build canonical and Open Graph URLs. */
  siteUrl: 'https://www.freightmove.au',

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
