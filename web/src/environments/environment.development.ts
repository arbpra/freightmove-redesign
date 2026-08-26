export const environment = {
  production: false,
  /** Laravel's `php artisan serve` default. */
  apiUrl: 'http://localhost:8000/api/v1',
  /** Canonical URLs still point at production so they are never indexed wrong. */
  siteUrl: 'https://www.freightmove.au',
};
