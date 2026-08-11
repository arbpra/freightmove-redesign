<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Named origins rather than '*', so a stray site cannot call the API from
    // a user's browser. Add the production frontend origin here at deploy time.
    /*
     * Every origin allowed to call the API, comma separated in FRONTEND_URL.
     *
     * A list rather than a single value because the API is on its own subdomain
     * and more than one front end legitimately talks to it — staging at
     * new.freightmove.au and, at cutover, the live site. A wrong or missing
     * entry fails as a browser CORS error with nothing in the API log, which is
     * a miserable thing to debug, so it is worth setting deliberately.
     *
     * Never widen this to '*'. With credentials in play the browser refuses it
     * anyway, and it would let any site call the API on a user's behalf.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:4200'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
