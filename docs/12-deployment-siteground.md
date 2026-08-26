# Deploying to SiteGround

Staging first: `new.freightmove.au` for the app, `api.freightmove.au` for the
API. **freightmove.au keeps running the old site untouched** — nothing here
affects it.

Two facts shape everything below:

1. **SiteGround shared hosting has no Node runtime**, so Angular cannot be built
   on the server. It is built locally and the output is committed to
   `deploy/web`, which the subdomain serves directly. That is why build output
   is in the repository.
2. **The API is on a different origin from the app**, so every request is
   cross-origin. `FRONTEND_URL` on the API must list the app's origin exactly,
   or the browser blocks responses before the app sees them — a CORS failure
   leaves nothing in the API log, so it is worth getting right first time.

---

## 1. Create the two sites

In **Site Tools → Domain → Subdomains**, create both if they do not exist:

| Subdomain | Document root |
| --- | --- |
| `new.freightmove.au` | `.../freightmove/deploy/web` |
| `api.freightmove.au` | `.../freightmove/api/public` |

Both document roots point *inside* one repository checkout. That is deliberate:
one `git pull` updates both.

Issue **Let's Encrypt certificates** for both (Site Tools → Security → SSL) and
turn on **HTTPS Enforce**. Do this before testing anything — a mixed-content
page will fail in ways that look like application bugs.

## 2. Connect the repository

**Site Tools → Devs → Git → Create Repository**, pointing at the GitHub repo,
and note the path it checks out to. Set the two document roots above to match
that path.

SiteGround's Git tool pulls; it does not run Composer or npm. Steps 3 and 4
cover what it cannot do.

## 3. Install PHP dependencies (SSH, once per deploy)

```bash
cd ~/www/freightmove/api
composer install --no-dev --optimize-autoloader
```

`vendor/` is not in the repository, so this is required before the API responds
at all.

## 4. Configure the API

```bash
cd ~/www/freightmove/api
cp .env.staging.example .env
php artisan key:generate
```

Fill in the database and mail credentials from Site Tools, then:

```bash
php artisan migrate --force
php artisan db:seed --class=SubscriptionPlanSeeder --force
php artisan config:cache
php artisan route:cache
```

`--force` is required because these are non-interactive on a non-local
environment. Re-run `config:cache` after **every** `.env` change — a cached
config ignores the file.

Writable directories, and the public disk:

```bash
chmod -R 775 storage bootstrap/cache
php artisan storage:link
```

`storage:link` is needed **once per environment**. Load photos are stored on the
`public` disk and served through `public/storage`; without the symlink every
uploaded photo 404s while the upload itself reports success, which looks like a
broken image rather than a missing deploy step.

### Email — Mailgun

Nine transactional emails run through this: quote received, quote accepted, new
message, the two verification decisions, carrier verified, load posted,
subscription receipt, plus password reset and contact enquiries
(`docs/06-api-spec.md`). If mail is wrong, carriers stop hearing that they won
work, and **nobody will report it, because there is nothing to see**.

`mailgun` is the transport to use. It is an HTTP API rather than SMTP, which
matters here: shared hosting throttles outbound SMTP and blocks some ports, and
a throttled handshake shows up as a slow request rather than as an error.

**In Mailgun**

1. Add a sending domain — `mg.freightmove.au` rather than the bare domain, so
   marketing sending later cannot damage the reputation of transactional mail.
2. Publish the DNS records it gives you (SPF, DKIM, and the tracking CNAME) and
   wait for the domain to show **Verified**. Sending before that works, and the
   mail lands in spam.
3. Copy the **Sending API key**.

**In `.env`**

```
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.freightmove.au
MAILGUN_SECRET=<sending api key>
MAILGUN_ENDPOINT=api.mailgun.net
MAIL_FROM_ADDRESS=no-reply@mg.freightmove.au
MAIL_FROM_NAME="FreightMove"
```

Then `php artisan config:cache`.

Three things that each look like a broken key when they are not:

- **`MAIL_FROM_ADDRESS` must be on the Mailgun domain.** Mailgun refuses a send
  from anything else, and the refusal is a generic 400.
- **US and EU are separate stacks.** A key issued on one returns 401 against the
  other. If the account was created in the EU, set
  `MAILGUN_ENDPOINT=api.eu.mailgun.net`.
- **A new domain is sandboxed** until verified, and sandbox domains only deliver
  to addresses you have explicitly authorised in Mailgun.

**Where enquiries go**

`FM_CONTACT_RECIPIENT` is the inbox the `/contact-us` form emails. **Set it.**
Left blank it falls back to `MAIL_FROM_ADDRESS`, which on Mailgun is
`no-reply@mg.freightmove.au` — a mailbox nobody opens. The fallback exists so a
fresh install does not silently drop enquiries, but on a live site it turns
"sent successfully" into "sent to nowhere", and the form will keep reporting
success to customers the whole time.

Every enquiry is stored in `contact_messages` regardless, with `notified_at`
recording whether the email actually went — so anything unsent stays findable:

```bash
php artisan tinker --execute="echo App\Models\ContactMessage::whereNull('notified_at')->count();"
```

**Prove it before trusting it**

```bash
php artisan mail:check you@example.com
```

It prints the transport, the domain, whether the key is set, and warns if the
From address is off-domain — then sends one message and reports what the
transport said. Accepted is not delivered: check the inbox *and* the spam
folder.

**Falling back to SMTP.** If Mailgun is not ready, set `MAIL_MAILER=smtp` and
fill the `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` block with a
SiteGround mailbox (Site Tools → Email → Accounts). Everything else is
unchanged — the application does not know or care which transport is in use.

### Optional: send email through the queue

Mail is sent **during the request** by default, which adds an SMTP handshake —
usually a second or two — to posting a load or accepting a quote. To move it off
the request, set `FM_MAIL_QUEUE=true`, re-run `php artisan config:cache`, and add
a worker in Site Tools → Devs → Cron Jobs, running **every minute**:

```
/usr/local/bin/php /home/USER/www/freightmove/api/artisan queue:work --stop-when-empty --max-time=55
```

Replace `USER` with your account name, and confirm the PHP path with `which php`
over SSH — SiteGround's cron does not always use the same binary as your shell.

`--stop-when-empty` means the process exits once the queue drains rather than
sitting resident, and `--max-time=55` guarantees it is gone before the next
minute's run starts, so the crons never stack. Worst-case delivery delay is about
a minute, which is fine for everything on the list.

**Do not set `FM_MAIL_QUEUE=true` without the cron.** Mail would be written to
the `jobs` table and never sent — silently, with no error anywhere. That is worse
than a slow request, which is why the default is off.

Failed sends land in `failed_jobs`; `php artisan queue:failed` lists them and
`queue:retry all` re-sends.

## 5. Deploy the app

Locally:

```bash
cd web
npm run deploy          # staging build -> deploy/web
cd ..
git add deploy && git commit -m "Deploy staging build" && git push
```

Then pull in Site Tools → Git.

`npm run deploy` builds with the **staging** configuration, which sets
`siteUrl` to `new.freightmove.au` and writes a `Disallow: /` robots.txt. The
script prints which kind of build it produced — check that line before pushing.

The Google Places key is **not** part of this build. It lives in the API's
`.env` as `FM_GOOGLE_MAPS_KEY` and is served to the browser by
`GET /api/v1/public/config`, so enabling autocomplete is an env edit plus
`php artisan config:cache` — no rebuild, no redeploy. Restrict it to
`https://new.freightmove.au/*` first; see `docs/11-security.md` §5a. Leaving it
blank is safe: the address fields fall back to plain text inputs.

For the eventual live deploy the command is `npm run deploy:live`, which builds
with the production configuration and a real robots.txt.

## 6. Verify, in this order

```bash
curl -i https://api.freightmove.au/api/v1/public/taxonomy      # 200 + JSON
curl -i https://new.freightmove.au/                            # 200 + HTML
curl -i https://new.freightmove.au/boat-transport              # 200 (SPA route)
curl -i https://new.freightmove.au/robots.txt                  # Disallow: /
```

Then in a browser, with devtools open:

- sign in as a seeded account — a **CORS error here means `FRONTEND_URL` does
  not exactly match** `https://new.freightmove.au` (no trailing slash);
- load `/load-board`, which proves the public API path end to end;
- send a password reset and confirm the emailed link points at
  `new.freightmove.au`, not the API subdomain;
- post a load as a shipper and quote on it from a second browser as a carrier —
  that one action exercises both receipt emails and proves the links in them
  resolve. Check the spam folder too; landing there is the common failure and it
  looks identical to success from the server's side;
- submit the `/contact-us` form and confirm it reaches the address in
  `FM_CONTACT_RECIPIENT` — then hit **reply** and check it addresses the
  customer, not the no-reply sender.

## 7. Migrating the live data (when you are ready to test it)

Create a **second** database, import a fresh `freightmove.au` backup into it,
put its credentials in the `LEGACY_DB_*` keys, then:

```bash
php artisan legacy:import --dry-run   # reports counts and warnings, writes nothing
php artisan legacy:import
```

Full detail, including what cannot be imported and why, is in
`docs/09-legacy-data-migration.md`. The import is re-runnable: every row carries
the legacy primary key and each step upserts against it, so importing a fresher
backup at cutover updates rather than duplicates.

---

## Before this becomes the live site

Staging is safe. Cutover is not, and these are decisions rather than code:

- [ ] **The three gates.** `FM_REQUIRE_SUBSCRIPTION_TO_QUOTE` locks out 289 of
      291 migrated carriers; `FM_REQUIRE_VERIFICATION_TO_QUOTE` locks out all of
      them. Both default off for that reason.
- [ ] **PayPal live credentials**, tested in sandbox first, and the webhook
      registered against the production API URL.
- [ ] **Real contact details.** `1300 123 456` and `info@freightmove.au` are
      placeholders in the header, footer, contact page and JSON-LD.
- [ ] **The unverifiable copy** — "reply within one business hour", the stats
      strip figures — is placeholder text, not supplied fact.
- [ ] **The legacy master-password backdoor** on the current site, which opens
      any shipper account. Live until that site is retired. See
      `docs/11-security.md` §5.
- [ ] **`/worldwide-transport`** has no equivalent page and is not redirected.
- [ ] **Point `FRONTEND_URL` at the live origin first** in the list, so emailed
      links stop going to staging.
- [ ] **Remove staging from the index** — or keep `new.freightmove.au` behind
      HTTP auth once the real site is live, so the two never compete.
