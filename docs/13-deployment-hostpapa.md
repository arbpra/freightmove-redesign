# Deploying to HostPapa

Moving `freightmove.au` (Angular) and `api.freightmove.au` (Laravel) off
SiteGround.

One fact shapes this entire document, and it is worth reading before you buy
anything:

> **HostPapa shared hosting has no SSH.** Their own knowledge base says so
> plainly — *"Unfortunately we do not offer SSH (Shell) or Telnet access to our
> shared hosting servers at this time."* SSH is a VPS and dedicated feature.

Every step of the SiteGround deployment used a shell: `composer install`,
`php artisan migrate`, `config:cache`, `storage:link`, `chmod`. None of those
can be typed on HostPapa shared. They are all still *possible* — cPanel's cron
runs commands as your user — but the workflow is genuinely different, so this is
not a find-and-replace of `docs/12-deployment-siteground.md`.

The second fact carries over unchanged: **the API is on a different origin from
the app**, so every request is cross-origin. `FRONTEND_URL` must list the app's
origin exactly or the browser blocks responses before the app sees them. A CORS
failure leaves nothing in the API log, so it is worth getting right first time.

---

## 0. The decision that changes everything

| | Shared (cPanel) | VPS (cPanel + root SSH) |
| --- | --- | --- |
| `composer install` on server | ✗ — `vendor/` must be uploaded | ✓ |
| `php artisan …` | only via one-shot cron jobs | ✓ directly |
| `git pull` to deploy | ✗ (unless Git Version Control is enabled) | ✓ |
| Deploy effort, each time | upload + extract two archives | one `git pull` |
| Real `crontab` | cPanel cron (fine) | ✓ |

**The honest recommendation is VPS.** Not because shared cannot run this app —
it can, and section B below is a complete working path — but because this is a
live marketplace taking PayPal payments, and on shared hosting every future
deploy becomes a manual file upload with no rollback. The SiteGround setup was
already awkward for exactly this reason; HostPapa shared is more awkward, not
less, because SiteGround at least had SSH.

If you take the VPS, **section A** is short: the SiteGround runbook applies
almost verbatim, and you get real cron as a bonus. If you take shared hosting,
work through **section B**.

Either way, do section 1 first.

---

## 1. Before you touch HostPapa

Collect these from the current server, because some cannot be recovered later.

**From the SiteGround `.env`** — the whole file. Copy it somewhere safe now. The
values you cannot regenerate are:

| Key | Why it matters |
| --- | --- |
| `APP_KEY` | Encrypts session payloads and anything using `Crypt`. Changing it is survivable here (sessions drop, people log in again) but only if you know you changed it. |
| `PAYPAL_CLIENT_ID` / `PAYPAL_CLIENT_SECRET` / `PAYPAL_WEBHOOK_ID` | Live credentials. Recoverable from the PayPal dashboard, but slowly. |
| `RESEND_KEY` | Recoverable from Resend. |
| `FM_CRON_TOKEN` | Regenerate freely — nothing depends on the old value. |
| `FM_GOOGLE_MAPS_KEY` | **Currently unset on the server**, which is why suburb autocomplete does not work. Set it during the move and you fix that at the same time. |
| `DB_*` / `LEGACY_DB_*` | Will change — new host, new credentials. |

**A fresh database dump.** In SiteGround Site Tools → MySQL → phpMyAdmin, export
the whole database, or use Site Tools' backup download. Take it as late as
possible before cutover; anything a customer does after the dump is lost unless
you re-import.

**The uploaded load photos.** These live on the `public` disk at
`api/storage/app/public` and are *not* in git. Download the folder over FTP.
Forgetting this is the classic move-day mistake: the site works perfectly and
every load photo is a broken image.

---

## Section A — HostPapa VPS

You have SSH, so `docs/12-deployment-siteground.md` applies with three
substitutions:

- **Site Tools → cPanel.** Subdomains are cPanel → Domains; SSL is cPanel →
  SSL/TLS Status (use AutoSSL); databases are cPanel → MySQL® Databases.
- **Real cron exists.** Use the command form, not the URL form — one entry
  covers everything and keeps `FM_CRON_TOKEN` out of a command line:

  ```
  * * * * * /usr/local/bin/php /home/USER/freightmove/api/artisan schedule:run >/dev/null 2>&1
  ```

  Confirm the binary with `which php` over SSH; cPanel servers often have
  several, and cron does not always pick the one your shell does.
- **PHP version** is set per-domain in cPanel → Select PHP Version. Laravel 12
  needs **8.2 or newer**; `api/composer.json` pins the platform to 8.2.33, so
  choose **8.2** to match exactly, or 8.3 if you would rather move forward.

Then jump to section B.9 (DNS and SSL) and B.10 (verify) — those are the same
whichever plan you are on.

---

## Section B — HostPapa shared hosting

### B.1 Create the two sites

In **cPanel → Domains**, you want this layout:

```
/home/USER/
├── public_html/          ← freightmove.au        (the Angular build)
└── freightmove/
    └── api/
        └── public/       ← api.freightmove.au    (Laravel's front controller)
```

Add `api.freightmove.au` as a subdomain and **set its document root to
`/home/USER/freightmove/api/public`**, not the default
`/home/USER/public_html/api`.

> **This is the one mistake that is a security incident rather than an
> inconvenience.** If the Laravel folder ends up inside `public_html`, then
> `https://freightmove.au/api/.env` is a public URL, and it contains your live
> PayPal secret, your database password and your `APP_KEY`. Only
> `api/public/` may be web-reachable. Everything else must sit outside
> `public_html`.

If cPanel refuses a document root outside `public_html` on your plan, stop and
open a support ticket rather than working around it — the workaround is what
exposes the file.

### B.2 Set the PHP version

**cPanel → Select PHP Version** → choose **8.2** (matching the platform pin in
`api/composer.json`). Then check these extensions are ticked:

```
bcmath  ctype  curl  dom  fileinfo  filter  hash  mbstring
openssl  pcre  pdo  pdo_mysql  session  tokenizer  xml  zip
```

`intl` is not required but is harmless. If `pdo_mysql` is off, the API returns a
500 with a connection error that reads like wrong credentials.

### B.3 Create the database

**cPanel → MySQL® Databases**: create a database, create a user, and add the
user to the database **with All Privileges**. Note all three values — cPanel
prefixes them with your account name (`user_freightmove`, not `freightmove`).

Import the dump in **phpMyAdmin → Import**. Two things go wrong here:

- **Upload size limit.** phpMyAdmin on shared hosting usually caps at 50 MB or
  so. If the dump is larger, gzip it (`.sql.gz` is accepted directly and is
  roughly 10× smaller), or upload the `.sql` over FTP and use phpMyAdmin's
  file-selection option if your build offers it.
- **Timeouts on a large import.** If it dies partway, the database is now half
  imported. Drop it and start again rather than re-running over the top.

This project uses **one** database for both `DB_*` and `LEGACY_DB_*` — that is
deliberate, not a mistake to fix. The legacy tables were renamed
(`legacy_users`, `legacy_jobs`, and so on) so both sets live together.

### B.4 Build the upload bundle

On your machine:

```bash
cd api
composer install --no-dev --optimize-autoloader
cd ../web
npm run deploy:live
cd ..
node scripts/bundle-hostpapa.mjs
```

That produces two archives in `dist-hostpapa/`:

| Archive | Extract into |
| --- | --- |
| `api.zip` | `/home/USER/freightmove/api/` |
| `public_html.zip` | `/home/USER/public_html/` |

The bundler exists because picking the right folders by hand every deploy is
where the expensive mistakes live. It deliberately **excludes your local
`.env`** — the server keeps its own, and uploading yours would overwrite the
live PayPal and database credentials with development ones. It also excludes
`public/storage`, which is a symlink pointing at a path on your laptop.

Afterwards, run `composer install` in `api/` again to restore the dev packages,
or the test suite will not run locally.

> `--no-dev` matters for more than size. It leaves PHPUnit and Faker off the
> server entirely, so a misconfigured route cannot reach them.

### B.5 Upload

**cPanel → File Manager** → navigate to the target folder → **Upload** → then
select the uploaded `.zip` and choose **Extract**. Delete the archive
afterwards.

FTP works too, but uploading ~15,000 small `vendor/` files individually over FTP
takes hours and frequently stalls partway, leaving a broken half-tree. Upload
the single archive and extract server-side.

### B.6 Write the `.env`

There is no shell, so create it in **File Manager**: navigate to
`/home/USER/freightmove/api/`, **+ File** → name it `.env` → **Edit**.

> File Manager hides dotfiles by default. Settings → **Show Hidden Files
> (dotfiles)**, or you will create a second `.env` on top of one you cannot see.

Start from `api/.env.example` and set at minimum:

```ini
APP_NAME=FreightMove
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...                      # see below
APP_URL=https://api.freightmove.au

# Every origin the browser may call the API from. First entry is canonical —
# password-reset links and PayPal returns land there.
FRONTEND_URL=https://www.freightmove.au,https://freightmove.au

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=USER_freightmove
DB_USERNAME=USER_fmuser
DB_PASSWORD=...

# Same database. The legacy tables were renamed so both live together.
LEGACY_DB_HOST=localhost
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=USER_freightmove
LEGACY_DB_USERNAME=USER_fmuser
LEGACY_DB_PASSWORD=...

MAIL_MAILER=resend
RESEND_KEY=...
MAIL_FROM_ADDRESS=noreply@freightmove.au
MAIL_FROM_NAME=FreightMove
FM_CONTACT_RECIPIENT=peter.freightmove@gmail.com
FM_PAYMENT_RECIPIENT=peter.freightmove@gmail.com
FM_LOAD_ALERT_ADMIN=peter.freightmove@gmail.com

FM_PAYMENT_GATEWAY=paypal
PAYPAL_MODE=live
PAYPAL_CLIENT_ID=...
PAYPAL_CLIENT_SECRET=...
PAYPAL_WEBHOOK_ID=...

FM_GOOGLE_MAPS_KEY=...
FM_CRON_TOKEN=...                       # 32+ chars; see B.8

FM_LOAD_ALERTS=true
FM_LOAD_ALERT_TEST_RECIPIENT=           # blank = real carriers. See B.11.
FM_SUBSCRIPTION_REMINDER_IGNORE_BEFORE=2026-09-07
```

`APP_KEY`: generate it locally rather than on the server —

```bash
cd api && php artisan key:generate --show
```

— and paste the `base64:…` result in. Reusing the SiteGround key is also fine
and keeps existing sessions valid.

**Mail needs no SMTP ports.** Resend is an HTTPS API, so the outbound port
blocking common on shared hosting does not apply. This is one thing that gets
easier on HostPapa, not harder.

### B.7 Running artisan without a shell

Laravel still needs `migrate`, `storage:link` and `config:cache` to have been
run. cPanel's cron executes commands as your user, so a **one-shot cron** is the
shell you do not have: create it, let it fire once, read the emailed output,
delete it.

**First, find the PHP binary.** Cron's default `php` is often an old version.
Create a cron in **cPanel → Cron Jobs**, set it to run every 5 minutes, put your
email in the notification field, and use:

```
which php; php -v; ls /opt/cpanel/ | head -30
```

Wait for the email. You are looking for a path whose `php -v` reports 8.2 or
newer — typically one of:

```
/opt/cpanel/ea-php82/root/usr/bin/php
/opt/alt/php82/usr/bin/php
/usr/local/bin/ea-php82
```

Delete that cron once you have the answer. Call the winner `PHP` below.

**Then run the setup commands**, one cron at a time, same pattern — every 5
minutes, notification email on, delete after it reports success:

```
cd /home/USER/freightmove/api && PHP artisan migrate --force
```

```
cd /home/USER/freightmove/api && PHP artisan storage:link
```

```
cd /home/USER/freightmove/api && PHP artisan db:seed --class=SubscriptionPlanSeeder --force
```

```
cd /home/USER/freightmove/api && PHP artisan config:cache && PHP artisan route:cache
```

`--force` is required: these refuse to run non-interactively outside local
without it.

`storage:link` is needed **once per environment**. Without it every uploaded
load photo 404s while the upload itself reports success — which looks like a
broken image rather than a missing deploy step.

**Re-run `config:cache` after every single `.env` edit.** A cached config
ignores the file completely, and this is the number one cause of "I changed the
setting and nothing happened".

**Permissions.** In File Manager, set `storage/` and `bootstrap/cache/` to
**755** recursively (Permissions → check *Recurse into subdirectories*). On
shared hosting the files are already owned by your user, so 755 is enough — do
not use 777, which some tutorials suggest and which many hosts refuse to serve.

Finally, restore the load photos: upload the `storage/app/public` contents you
downloaded in section 1 into `/home/USER/freightmove/api/storage/app/public/`.

### B.8 The scheduler

Two emails are sent by a daily sweep rather than by a request — the warning
before a subscription's end date and the notice after it. Nothing triggers them
except the scheduler, so without this they simply never happen: no error, no log
line, just carriers quietly lapsing.

**cPanel → Cron Jobs**, one entry:

```
*/5 * * * * cd /home/USER/freightmove/api && PHP artisan schedule:run >/dev/null 2>&1
```

Every five minutes rather than every minute, because shared-hosting acceptable
use policies commonly forbid sub-5-minute crons. The daily tasks still fire; the
only cost is up to five minutes of delay on a job that runs once a day.

`>/dev/null 2>&1` suppresses the per-run email. Leave it off while you are
testing, then add it — otherwise you get 288 emails a day and it counts against
your inode quota.

**You now have real cron, so you do not need the token-guarded URL form** that
SiteGround required (`docs/12` §"The URL form"). Those endpoints still exist and
still work; the command above supersedes them. Keep `FM_CRON_TOKEN` set to a
32+ character secret anyway — with it unset the URLs fail closed, which is what
you want for a route that can mail every carrier.

Before trusting it on real data, prove it writes nothing:

```
cd /home/USER/freightmove/api && PHP artisan subscriptions:remind --dry-run
```

### B.9 DNS and SSL

Order matters, and the wrong order means downtime.

1. **Lower the TTL first**, at least 24 hours ahead, on whatever manages the
   `freightmove.au` DNS — drop it to 300 seconds. If you skip this, the cutover
   takes as long as the old TTL, and half your visitors hit the old server while
   the other half hit the new one.
2. **Get the new site working before DNS moves.** Use HostPapa's temporary URL,
   or add entries to your local `hosts` file pointing both hostnames at the
   HostPapa IP. Test properly. Do not cut DNS over to an untested server.
3. **Then repoint DNS** — nameservers, or A records for `freightmove.au`,
   `www` and `api`.
4. **Then issue SSL.** cPanel → SSL/TLS Status → **Run AutoSSL**. Let's Encrypt
   validates over HTTP, so it can only succeed *after* DNS points here. Until
   the certificate exists, every API call fails on a certificate error that
   looks exactly like a CORS problem.
5. **Force HTTPS**, cPanel → Domains → toggle *Force HTTPS Redirect* per domain.

Keep the SiteGround account alive for a week. DNS caches lie, and it is the
cheapest possible rollback.

### B.10 Verify, in this order

```bash
curl -i https://api.freightmove.au/api/v1/public/taxonomy   # 200 + JSON
curl -s https://api.freightmove.au/api/v1/public/config     # google_maps_key NOT null
curl -i https://freightmove.au/                             # 200 + HTML
curl -i https://freightmove.au/boat-transport               # 200, not 404 — SPA routing
curl -i https://freightmove.au/robots.txt                   # must NOT say Disallow: /
```

A 404 on `/boat-transport` means `.htaccess` did not survive the upload — File
Manager hides dotfiles, and zip extraction sometimes skips them. Check that
`public_html/.htaccess` exists.

Then in a browser with devtools open:

- **Sign in.** A CORS error here means `FRONTEND_URL` does not exactly match the
  origin — no trailing slash, and `www` and bare are different origins.
- **Open `/load-board`**, proving the public API path end to end.
- **Open a load and check the suburb autocomplete** on the post-a-load form. If
  the fields are plain text, either `FM_GOOGLE_MAPS_KEY` is unset (check the
  `config` curl above) or the key's HTTP-referrer restriction does not list the
  live domains. See `docs/11-security.md` §5a.
- **Send a password reset** and confirm the link points at `freightmove.au`, not
  the API subdomain.
- **Post a load as a shipper, quote as a carrier from a second browser.** That
  one action exercises both receipt emails and proves the links resolve. Check
  spam — landing there is the common failure and it looks identical to success
  from the server's side.
- **Submit `/contact-us`**, then hit reply and check it addresses the customer
  rather than the no-reply sender.
- **Make one real PayPal subscription** for the smallest plan, and confirm the
  webhook arrives. Refund it afterwards.

### B.11 Decisions that are not code

These are live-data choices the move surfaces. None is a bug.

- [ ] **The PayPal webhook URL.** In the PayPal dashboard it must point at
      `https://api.freightmove.au/api/v1/webhooks/paypal`. It currently points
      at the retired app's path, so subscription activations are not being
      confirmed automatically.
- [ ] **`FM_LOAD_ALERT_TEST_RECIPIENT`.** While set, every new-load alert goes
      to that one address instead of all 282 carriers. Blank it only when you
      are ready for real fan-out — and know that the first load posted
      afterwards mails everyone.
- [ ] **`FM_SUBSCRIPTION_REMINDER_IGNORE_BEFORE`.** Without a date here, the
      first scheduler run emails 76 carriers about subscriptions that lapsed as
      long ago as 2024. Set it to roughly the cutover date.
- [ ] **`FM_BOARD_RECENCY_DAYS`.** At the default 7, the board shows 1 of 102
      loads, because the imported loads are older than a week. Raise it or set
      it to 0 while the marketplace refills.
- [ ] **The two entitlement gates** (`FM_REQUIRE_SUBSCRIPTION_TO_QUOTE`,
      `FM_REQUIRE_SUBSCRIPTION_FOR_CONTACTS`) both default off, which is
      intended for now — see `docs/10-domain-rules.md` §R3.
- [ ] **Rotate the Google Maps key.** It is in git history at `82d2310`. A move
      is a natural moment to do it: new key, new `.env`, no rebuild needed.

---

## Redeploying, afterwards

Front-end-only change (most changes):

```bash
cd web && npm run deploy:live && cd ..
node scripts/bundle-hostpapa.mjs
```

Upload `public_html.zip`, extract over `public_html`, done. No cron, no cache
clear — the build filenames are content-hashed, so browsers pick up the change
immediately.

API change:

```bash
cd api && composer install --no-dev --optimize-autoloader && cd ..
node scripts/bundle-hostpapa.mjs
```

Upload `api.zip`, extract over the API folder, then run a one-shot cron for
`config:cache && route:cache` — and `migrate --force` if the change added a
migration. Then `composer install` locally to get your dev packages back.

**`vendor/` only needs re-uploading when `composer.json` changed.** If it did
not, extract `api.zip` and skip nothing — the archive is the same either way,
it is just larger than it needs to be.

---

## What changed from SiteGround

| | SiteGround | HostPapa shared |
| --- | --- | --- |
| Control panel | Site Tools | cPanel |
| Shell | SSH (no `crontab`) | none at all |
| Deploy | `git pull` in Site Tools | upload + extract two archives |
| `vendor/` | `composer install` on server | shipped in `api.zip` |
| Angular build | committed to `deploy/web`, served by symlink | extracted into `public_html` |
| artisan | typed over SSH | one-shot cron jobs |
| Scheduler | token-guarded cron **URL** | `artisan schedule:run` **command** |
| Mail | Resend over HTTPS | unchanged — no SMTP ports needed |

`deploy/web` stays in git. It is no longer strictly required — nothing symlinks
to it on HostPapa — but it is what `scripts/bundle-hostpapa.mjs` packages, and
it keeps the built output reviewable in diffs.
