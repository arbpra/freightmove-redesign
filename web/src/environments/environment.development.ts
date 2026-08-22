export const environment = {
  production: false,
  /** Laravel's `php artisan serve` default. */
  apiUrl: 'http://localhost:8000/api/v1',
  /** Canonical URLs still point at production so they are never indexed wrong. */
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
  googleMapsApiKey: 'AIzaSyAGsKkmO3BXi48Q6zhoxwrfK9z_mIM9v5Y',
};
