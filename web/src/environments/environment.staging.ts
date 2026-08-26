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
};
