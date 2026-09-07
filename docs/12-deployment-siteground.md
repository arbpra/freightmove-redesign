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

### Email — Resend

Twelve transactional emails run through this: quote received, quote accepted,
new message, the two verification decisions, carrier verified, load posted,
the subscription receipt and its operator copy, the two subscription reminders,
plus password reset and contact enquiries (`docs/06-api-spec.md`). If mail is
wrong, carriers stop hearing that they won work, and **nobody will report it, because there is
nothing to see**.

Resend is an HTTP API rather than SMTP, which matters here: shared hosting
throttles outbound SMTP and blocks some ports, and a throttled handshake shows
up as a slow request rather than as an error.

**In Resend**

1. **resend.com/domains** → add `freightmove.au`
2. Publish the SPF and DKIM records it gives you, and wait for **Verified**.
   Sending before that works, and the mail lands in spam.
3. **resend.com/api-keys** → create a key. It starts `re_` and is shown once.

**In `.env`**

```
MAIL_MAILER=resend
RESEND_KEY=re_...
MAIL_FROM_ADDRESS=no-reply@freightmove.au
MAIL_FROM_NAME="FreightMove"
```

Then `php artisan config:cache`.

**`MAIL_FROM_ADDRESS` must be on the verified domain.** Resend refuses a send
from anything else, and the refusal reads like a bad key.

That includes the contact address. `peter.freightmove@gmail.com` is where mail
should *arrive*; it cannot be where mail is *sent from*. Nobody can prove
ownership of a gmail.com address to Resend, so a send from it is refused
outright — and if it were not, it would fail SPF and DKIM at the receiving end
and land in spam. The sender stays `no-reply@send.freightmove.au`.

The contact address reaches you as the **recipient** of those messages —
`FM_CONTACT_RECIPIENT`, `FM_PAYMENT_RECIPIENT`, `FM_LOAD_ALERT_ADMIN` — not as
the sender of them. Separately, `ContactEnquiry` sets reply-to to the customer
who filled the form, so hitting reply on an enquiry answers *them* rather than
the no-reply mailbox.

**Where enquiries go**

`FM_CONTACT_RECIPIENT` is the inbox the `/contact-us` form emails. **Set it.**
Left blank it falls back to `MAIL_FROM_ADDRESS`, which is a no-reply mailbox
nobody opens — turning "sent successfully" into "sent to nowhere" while the form
keeps reporting success to customers.

Every enquiry is stored in `contact_messages` regardless, with `notified_at`
recording whether the email actually went:

```bash
php artisan tinker --execute="echo App\Models\ContactMessage::whereNull('notified_at')->count();"
```

**Where payment notices go**

`FM_PAYMENT_RECIPIENT` is where a "payment received" copy goes when a carrier
pays — comma-separated for more than one person, falling back to
`FM_CONTACT_RECIPIENT`. Under the PayPal gateway nobody here touches the
transaction: the carrier pays, the capture confirms, the subscription switches
itself on. This is the only notice that money arrived.

**Prove it before trusting it**

```bash
php artisan mail:check you@example.com
```

It prints the transport and whether the key is set, then sends one message and
reports what came back. Accepted is not delivered: check the inbox *and* the
spam folder.

**Not ready yet?** Leave `MAIL_MAILER=log`. The application works normally,
enquiries are stored, and the rendered email goes to `storage/logs` — nothing is
lost and nothing errors.

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

### The scheduler — required for subscription reminders

Two emails are sent by a daily sweep rather than by a request: the warning
before a subscription's end date, and the notice after it. Nothing triggers
them except the scheduler, so on a server with no cron they simply never
happen — with no error, nothing in the log, and no symptom beyond carriers
quietly lapsing.

Add one cron in **Site Tools → Devs → Cron Jobs**, running **every minute**:

```
/usr/local/bin/php /home/USER/www/freightmove/api/artisan schedule:run
```

Replace `USER`, and confirm the PHP path with `which php` over SSH — SiteGround's
cron does not always use the same binary as your shell. This single entry runs
everything in `routes/console.php`, including the token and password-reset
pruning that was already there.

**Look at it before trusting it.** On a server holding real carriers, run the
dry form first — it writes nothing and sends nothing:

```bash
php artisan subscriptions:remind --dry-run
```

The `--date` option runs the sweep as though today were some other day, so you
can see what tomorrow will do before it does it:

```bash
php artisan subscriptions:remind --date=2026-10-01 --dry-run
```

The cadence, all in `.env`:

| Key | Default | What it does |
| --- | --- | --- |
| `FM_SUBSCRIPTION_REMINDER_DAYS` | `5,3,1` | Days **before** the end date. |
| `FM_SUBSCRIPTION_REMINDER_AFTER_DAYS` | `3,7,15` | Days **after** it. |
| `FM_SUBSCRIPTION_REMINDER_MONTHS` | `0` | Then monthly on the anniversary, for this many months. `0` never stops. |
| `FM_SUBSCRIPTION_REMINDER_IGNORE_BEFORE` | blank | Never remind about a period that ended before this date. |

**Set `FM_SUBSCRIPTION_REMINDER_IGNORE_BEFORE` before the first live run.** 88
of the 90 migrated subscription periods are already expired, some since 2024,
and the monthly cadence never stops by default — so every one of those carriers
is in scope the moment the sweep first runs. Setting it to roughly today's date
leaves the history alone and reminds normally from here on.

Measured, not guessed. Against the imported data as it stands:

```
$ php artisan subscriptions:remind --dry-run          # no cutoff
  expired    : 76     (would send)
  suppressed : 1344   (backlog milestones recorded, not emailed)

$ FM_SUBSCRIPTION_REMINDER_IGNORE_BEFORE=2026-09-01 ... --dry-run
  expired    : 0
```

Those 76 are not spammed with a backlog — only the newest milestone of each is
sent, which is what the 1,344 suppressed rows are — but they each get one email
about a subscription they walked away from, in some cases two years ago.
Mailing people who have stopped engaging is what generates spam complaints, and
complaints are scored against the sending domain, which is the same domain the
quote and password-reset emails leave on.

Run the dry form on the server before deciding. It is the same two commands.

Re-sending is otherwise impossible: `subscription_reminders` records each send
under a unique index, so running the command by hand as often as you like is
safe.

**The record is in the admin console** at `/admin/reminders` — every reminder,
who received it, which milestone it was, and when. It also lists the milestones
that were reached and deliberately not emailed, so an empty inbox has an
explanation rather than a mystery.

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
`php artisan config:cache` — no rebuild, no redeploy. Restrict it first; see
`docs/11-security.md` §5a. The referrer list must name the domain the browser
is actually on: after the cutover that is `https://freightmove.au/*` and
`https://www.freightmove.au/*`, not just `https://new.freightmove.au/*`. A key
restricted to the old staging host loads on the live site and then has every
prediction rejected — which looks exactly like a broken integration.

Leaving it blank is safe: the address fields fall back to plain text inputs.
That fallback is silent by design, so the way to tell the two apart is to ask
the API what it is serving:

```bash
curl -s https://api.freightmove.au/api/v1/public/config
```

`"google_maps_key":null` means the `.env` value is missing or the config cache
is stale. A key in the response means the env side is right and anything still
wrong is on the Cloud project — referrers, the Places API not being enabled, or
billing.

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
      registered against the production API URL. The integration itself is
      built and tested — checkout, redirect, capture, signature-verified
      webhooks and refunds. Switching it on is `FM_PAYMENT_GATEWAY=paypal`
      plus `PAYPAL_CLIENT_ID` / `PAYPAL_CLIENT_SECRET` / `PAYPAL_WEBHOOK_ID`,
      then `php artisan config:cache`. Left on `manual`, every carrier who
      subscribes waits for an admin to confirm the payment by hand.
- [x] **Real contact details.** `+61 407 243 242` and
      `peter.freightmove@gmail.com`, both from `web/src/app/layout/public-nav.ts`
      and mirrored into `FM_CONTACT_RECIPIENT`, `FM_PAYMENT_RECIPIENT` and
      `FM_LOAD_ALERT_ADMIN`. `MAIL_FROM_ADDRESS` is deliberately *not* that
      address — see below.
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
