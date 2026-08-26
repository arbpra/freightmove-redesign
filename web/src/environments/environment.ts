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
};
