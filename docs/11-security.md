# Security

Findings from the audit of 9 August 2026, the fixes applied, and the controls
that must hold. Regression cover is `api/tests/Feature/SecurityTest.php` — each
test names the finding it guards.

## 1. Audit scope and clean results

Checked across `api/app`, `api/config`, `api/routes` and `web/src`:

| Area | Result |
| --- | --- |
| SQL injection | **Clean.** No string interpolation into queries. One `selectRaw` uses a static string. All filters go through Eloquent bindings. |
| Mass assignment from raw input | **Clean.** No `$request->all()` reaches a model. Every write uses a Form Request and explicit keys. |
| Hardcoded secrets | **Clean.** No keys or credentials in source. `.env` is not tracked by git. |
| Dependency CVEs | **Clean.** `npm audit` 0, `composer audit` 0. |
| Sensitive fields in responses | **Clean.** `UserResource` exposes no hash, token or `legacy_id`. |
| Ownership enforcement | **Clean.** Every job query is scoped to the signed-in user *and* checked against `FreightJobPolicy`. |
| Token revocation | **Clean.** Logout deletes the current token; a password reset deletes all of them. |
| Legacy master-password backdoor | **Not reproduced.** See §5. |

## 2. Findings and fixes

### F1 — Access tokens never expired (high)

`config('sanctum.expiration')` was `null`, so a token was valid forever. Tokens
are stored in browser `localStorage`, so one leaked through any means stayed
replayable indefinitely.

**Fixed.** Sanctum config published with `SANCTUM_TOKEN_MINUTES` (default 720 —
12 hours, a working day). `sanctum:prune-expired` and `auth:clear-resets` run
daily from `routes/console.php`, so expired rows do not accumulate for offline
brute forcing if the database is ever dumped.

### F2 — Password policy accepted "password" (medium)

`Password::defaults()` was never configured, so Laravel's default applied:
`min:8` and nothing else.

**Fixed.** `AppServiceProvider::definePasswordPolicy()` now requires 10
characters with letters and numbers, plus mixed case and a
**Have I Been Pwned** breach check in production. The breach check uses
k-anonymity — only the first five characters of the SHA-1 hash leave the server,
never the password — and is skipped outside production so tests and local work
do not depend on a third-party service.

### F3 — No rate limit on authenticated endpoints (medium)

Only the six auth routes were throttled. Every other endpoint could be called as
fast as the network allowed by anyone holding a valid token.

**Fixed.** Three named limiters in `AppServiceProvider`:

| Limiter | Budget | Keyed by |
| --- | --- | --- |
| `auth` | 6/min | IP **and** submitted email |
| `api` | 120/min | user id, falling back to IP |
| `writes` | 30/min | user id, falling back to IP |

Keying `auth` by email as well as IP matters: keyed on IP alone, an attacker
spread across addresses gets unlimited attempts against one account. Keying the
others by user id stops one abusive client exhausting the quota for everyone
behind a shared address.

### F4 — Response hardening absent (low)

**Fixed.** `SecurityHeaders` middleware sets `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy`, `Cross-Origin-Resource-Policy`, a
CSP, and HSTS **only over HTTPS** (sending it over plain HTTP is meaningless and
on a shared host can lock sibling sites out).

Two deliberate details:

- It is **prepended to the global stack**, not appended to the API group. As a
  group middleware the headers were missing from 401/403/429 responses, because
  those are rendered from an exception raised deeper in the stack. Prepending
  makes it the outermost layer, so every response passes back out through it.
  A test asserts the headers are present on an unauthenticated 401.
- The CSP is **scoped**: `default-src 'none'` for `api/*`, where nothing should
  ever load or execute, and a lighter policy elsewhere. Applied globally the
  strict policy strips the styling off any HTML the app serves, including
  Laravel's own error pages.

### F5 — Icon component relied on types for a sanitiser bypass (low)

`fm-icon` renders inline SVG through `bypassSecurityTrustHtml`. The value came
from `FM_ICONS[name]` with `name` typed as `IconName` — but a type cannot stop a
value arriving from an API response at runtime.

**Fixed.** The lookup now checks `hasOwnProperty` and falls back to an empty
string, so only keys genuinely present in the registry reach the bypass. The
registry, not the type, is the boundary.

### F6 — Password reset was unusable for real accounts (medium)

`POST /auth/forgot-password` returned 500 for any **registered** address.
Laravel's default `ResetPassword` notification builds its link from the
`password.reset` *web* route; an API-only application has no such route, so the
notification threw `RouteNotFoundException`. Unregistered addresses never reach
the notification, and that was the only case the original test covered, so it
passed and the feature looked complete.

Two consequences worth naming: a user locked out of their account had no way
back in, and the 500 also made the endpoint an oracle — a registered address
behaved measurably differently from an unregistered one, defeating the
deliberate "same answer either way" design directly above it in the controller.

Fixed by `AppServiceProvider::definePasswordResetUrl()`, which builds
`{FRONTEND_URL}/reset-password/{token}?email=…`. Now covered by
`PasswordResetTest`, using a **real account** — including an assertion on the
link itself.

### F7 — The contact form was an open mail relay by design (medium, pre-empted)

The form had no backend at all, so nothing shipped broken; but the obvious
implementation — validate, `Mail::send`, done — would have been an
unauthenticated endpoint that sends attacker-chosen text to a fixed inbox at
whatever rate they like. As built it carries an hourly limiter keyed to both IP
and address, a honeypot, and a 20-character message floor. The notification is
**plain text**: every field is customer-supplied, and a text part cannot be
coaxed into rendering markup or a link the sender did not intend. The customer's
address goes in `Reply-To`, never `From`, so SPF and DMARC still pass.

## 3. Controls that must hold

Breaking any of these reopens a finding.

1. **`APP_DEBUG=false` in production.** With it on, Laravel returns exception
   messages in the API envelope, and the Angular client displays
   `response.error.message` directly to the user — so file paths and SQL would
   be shown on screen. `.env.example` carries this warning.
2. **`FRONTEND_URL` set to the real origin.** It is the sole entry in
   `config/cors.php` `allowed_origins`. Never widen it to `*`.
3. **No user-controlled value in `$fillable` fields that grant privilege.**
   `role` and `status` are fillable on `User`; nothing currently mass-assigns
   them from request input, and `RegisterRequest` whitelists shipper/carrier
   only. Any future profile-update endpoint must exclude both. A test asserts
   registering as `admin` is refused.
4. **New endpoints get a policy, not just a role check.** Role middleware proves
   *what kind* of user is calling; only a policy proves they own the record.
5. **New third-party keys go in `.env`.** The legacy app hardcoded a Google Maps
   key in a controller.
6. **Unauthenticated endpoints that send email keep their own limiter.**
   `POST /contact` is on `throttle:contact` (5/hour per IP, 3/hour per address),
   not the general read allowance — an open form that sends mail is a relay.
7. **Emailed links point at `FRONTEND_URL`, never at a named web route.** This
   application serves an API only; `route('password.reset')` does not exist, and
   relying on it made forgotten-password throw for every registered address.
   See F6.

## 3a. File uploads

`POST /carrier/documents` is the only endpoint that accepts a file. Every rule
here is covered by a test in `CarrierVerificationTest`.

| Control | How |
| --- | --- |
| MIME by **content** | `mimetypes:` reads the file through finfo. `mimes:` checks the extension as well — content alone would accept a PDF named `.exe`, extension alone would accept anything renamed `.pdf`. |
| Outside the web root | `local` disk, rooted at `storage/app/private`. No path serves it over HTTP. |
| Randomised names | `$file->store()` hashes the name. The uploaded filename is kept as *data* only — it is attacker-controlled text and never decides where a byte lands. |
| Size cap | `FM_MAX_UPLOAD_KB`, default 8MB. |
| Rate limit | Its own `uploads` limiter, 20/hour, below ordinary writes. |
| Read back | `GET .../download`, behind auth and `VerificationDocumentPolicy`. No public URL and no signed link — a link that works without a session is a link that still works after it is forwarded. |
| Always attachment | `Content-Disposition: attachment`, never inline. A file rendered under our own origin would be stored XSS whatever the MIME check allowed. |
| Path never leaks | `file_path` is in `$hidden`. |

**A note on testing uploads.** `UploadedFile::fake()` overrides `getMimeType()`
to derive the type from the *filename*, so a faked upload cannot exercise
content detection at all — it reports `application/pdf` for anything named
`.pdf`, and a test written with it passes while proving nothing. The
disguised-executable test therefore constructs a real `UploadedFile` over a real
temp file. Anyone rewriting that test with the fake helper will silently delete
the coverage.

## 4. Accepted risks

**Tokens in `localStorage`.** Readable by any script that achieves XSS. The
alternative — httpOnly cookies — needs CSRF protection and same-site
constraints that complicate a decoupled SPA on a separate origin. Mitigated by:
Angular escaping all interpolation by default, the only `innerHTML` in the app
being the registry-guarded icon set, a strict CSP on API responses, and the
12-hour token lifetime bounding the replay window.

**Verbose network-failure messages.** `describeError` names the API base URL
when a request cannot connect. That is a development aid on a public page; it
discloses only the configured URL, which is already visible in the network tab.

## 5. Legacy production issue — not ours, still live

The old codebase's `loginAuth::ShipperLogin` accepts a hardcoded literal
password that signs into **any** shipper account regardless of the real one.

- **Not reproduced in V2.** Authentication is `Hash::check` against the stored
  bcrypt hash with no bypass path.
- **Believed still live on freightmove.au** until that site is retired. This is
  an active compromise of every existing account and is independent of the
  rebuild — it should be closed on the old site now, not at cutover.

Also not carried over: a Google Maps API key committed in a legacy controller.
If that key is still valid it should be rotated regardless of this project.

## 6. Still to do

- **Verify the legacy backdoor is closed** on the production site (§5).
- **Rotate the legacy Google Maps key** if still in use.
- ~~**File upload hardening**~~ ✅ done with the first upload endpoint
  (`POST /carrier/documents`). All four points are implemented and tested:
  content-based MIME via finfo, storage on the private disk outside the web
  root, randomised filenames, and a configurable size cap. See §3a.
- **Audit logging** for admin actions and role changes.
- **Email verification** is modelled (`email_verified_at`) but not enforced.
- **Contact form spam review.** The honeypot and rate limits stop scripted
  submissions; a determined human is not stopped by either. If `contact_messages`
  starts filling with junk, that is the signal to add a captcha — not before,
  since a captcha costs every genuine enquiry something too.
- **Two-factor authentication** for admin accounts.
