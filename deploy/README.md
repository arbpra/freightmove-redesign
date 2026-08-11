# deploy/

**Generated. Do not edit anything in here by hand.**

`deploy/web/` is the built Angular application, produced by `npm run deploy`
(staging) or `npm run deploy:live` in `../web`.

It is committed to the repository — unusually for build output — because
SiteGround shared hosting has no Node runtime, so `ng build` cannot run on the
server. The subdomain's document root points straight at `deploy/web`, and a
`git pull` is the whole deployment.

The API needs no equivalent: PHP runs from source, and `composer install`
provides `vendor/` on the server.
