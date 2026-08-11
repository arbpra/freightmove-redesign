# REST API Specification

## 1. API Conventions

- Base URL: /api/v1
- JSON responses with consistent envelope format
- Authenticated routes use Sanctum tokens
- Pagination for list endpoints
- Standard HTTP verbs: GET, POST, PUT, PATCH, DELETE

## 1a. What is actually registered

Everything below this section is the **target** specification. As at
9 August 2026 the following are implemented and reachable; confirm with
`php artisan route:list --path=api/v1`.

| Endpoint | Status |
| --- | --- |
| `POST /auth/register` | Built — carriers must choose a `subscription_plan` |
| `POST /auth/login` | Built — upgrades legacy bcrypt hashes on success |
| `POST /auth/logout` | Built |
| `GET /auth/me` | Built |
| `POST /auth/forgot-password` | Built — **the emailed link was broken until 9 Aug**, see below |
| `POST /auth/reset-password` | Built — single use, revokes other sessions |
| `GET /shipper/overview` | Built |
| `GET /carrier/overview` | Built |
| `POST /contact` | Built — stored then emailed, honeypot + hourly limiter |

Shipper job endpoints are also built:

| Endpoint | Status |
| --- | --- |
| `GET/POST /shipper/jobs` | Built — list is paginated, filterable by status, searchable |
| `GET/PATCH/DELETE /shipper/jobs/{job}` | Built — ownership via `FreightJobPolicy` |
| `POST /shipper/jobs/{job}/publish` | Built |
| `POST /shipper/jobs/{job}/cancel` | Built |
| `POST /shipper/jobs/{job}/relist` | Built — bumps the load, 24h cooldown |
| `POST /shipper/jobs/{job}/complete` | Built — shipper signs the load off |
| `GET/POST /jobs/{job}/reviews` | Built — both sides, once each, after completion |
| `GET /shipper/jobs/{job}/quotes` | Built — cheapest first, with carrier summary |
| `POST /shipper/quotes/{quote}/accept` | Built — atomic; declines the rest, books the load |
| `POST /shipper/quotes/{quote}/decline` | Built — leaves the load open |
| `GET /carrier/board` | Built — filters combine freely, recency window |
| `GET /carrier/board/{job}` | Built |
| `POST /carrier/board/{job}/quotes` | Built — one per carrier per load, subscription gate |
| `GET /carrier/quotes` | Built |
| `DELETE /carrier/quotes/{quote}` | Built — withdraw while pending |
| `GET /conversations` | Built — my threads, unread counts, last message |
| `GET /conversations/{conversation}` | Built — thread; opening marks incoming read |
| `POST /conversations` | Built — opens or reuses the thread for a load |
| `POST /conversations/{conversation}/messages` | Built |
| `GET /notifications` | Built — own feed, `?unread=1`, paginated |
| `GET /notifications/unread-count` | Built — count only, for the badge poll |
| `PATCH /notifications/{notification}/read` | Built |
| `POST /notifications/read-all` | Built |
| `GET/PATCH /carrier/profile` | Built — own record only, no id in the path |
| `GET/POST /carrier/documents` | Built — content-checked uploads, private disk |
| `GET /carrier/documents/{document}/download` | Built — streams to owner or admin |
| `DELETE /carrier/documents/{document}` | Built — withdraw while pending only |
| `GET /admin/overview` | Built — see the note below |
| `GET /admin/users` | Built — search, role/status/legacy filters |
| `POST /admin/users/{user}/status` | Built — suspend or reinstate; **no role changes** |
| `GET /admin/jobs` | Built — every load, read-only |
| `GET /admin/verifications` | Built — review queue, oldest first |
| `POST /admin/documents/{document}/approve` | Built |
| `POST /admin/documents/{document}/reject` | Built — reason required |
| `GET /public/loads/recent` | Built — home page teaser, five rows |
| `GET /public/loads` | Built — the full board, paginated and searchable |
| `GET /public/subscription-plans` | Built — the carrier pricing table |
| `GET /carrier/subscription` | Built — current, pending, history, eligibility |
| `POST /carrier/subscription/trial` | Built — two months, once per account |
| `POST /carrier/subscription/checkout` | Built — reserves a plan, returns a PayPal approval URL |
| `POST /carrier/subscription/capture` | Built — the return leg; verifies with PayPal |
| `POST /webhooks/paypal` | Built — signature-verified, unauthenticated by necessity |
| `POST /carrier/subscription/{subscription}/cancel` | Built — stops renewal, keeps the period |
| `GET /admin/subscriptions` | Built — payment queue |
| `POST /admin/subscriptions/{subscription}/confirm` | Built — switches it on |
| `GET /public/taxonomy` | Built — categories, truck types, availability |
| `GET /public/suburbs?q=&state=` | Built — autocomplete over 15,329 suburbs |
| `GET /public/routes/{pickup}/{dropoff}` | Built — cached distance, by suburb id |

Everything else in this document — the remaining admin queues, public
content — is still to be written.

### Notes on the newest endpoints

**`POST /shipper/jobs/{job}/relist`** bumps an open load back to the top of the
carrier board and restarts its recency window. It is a separate verb rather than
a side effect of PATCH: the legacy site conflated the two by touching
`date_updated` on every edit (R5). Allowed only while the load is `published`,
`matched` or `quoted`. Inside the cooldown it answers **429** with the message
and `errors.next_relist_at`; the window is `FM_RELIST_COOLDOWN_HOURS`, default
24, and 0 disables it.

**`GET /public/suburbs`** requires `q` of at least 2 characters, optionally
narrowed by `state`, and returns at most 15 rows. Prefix matches sort first.
LIKE wildcards in `q` are escaped, so `%` cannot dump the table.

**`POST /contact`** stores the enquiry in `contact_messages` *before*
attempting to email it, and a mail failure does not fail the request — the
message did arrive, and telling the customer otherwise would lose it twice.
`notified_at` records whether the email actually went, so anything unsent stays
findable. Held down by a `contact` limiter (5/hour per IP, 3/hour per address),
a honeypot field, and a 20-character minimum message. A filled honeypot gets the
same 201 as a real submission — telling a script it was caught only teaches
whoever wrote it.

**Public load endpoints.** `GET /public/loads/recent` backs the home page
strip; `GET /public/loads` is the full board at `/load-board`, with search,
category filter and pagination. Both render through **one** `PublicLoadResource`,
so what a guest may see is defined once — two hand-rolled copies is how a field
gets added to one and quietly leaks from the other.

What a guest may see is deliberately narrow — lane, freight type, weight, availability,
quote count and how recently it was posted. Withheld on purpose:

- **budget**, which is the shipper's negotiating position;
- **description**, which is where site contacts and gate codes end up;
- **the shipper's identity**, since publishing who ships what on which lane is
  commercially sensitive and an open invitation to approach them directly — the
  disintermediation the platform exists to prevent;
- **the load id**, so there is nothing for a guest to try fetching directly.
  An opaque `ref` (`FM-000123`) is sent instead, so the client can track rows
  across pages without the real id leaving the server.

The board also reports what quoting currently requires
(`quoting.requires_subscription` / `requires_verification`), so the page can say
"sign in to quote" or "subscribe to quote" accurately instead of hardcoding a
rule that lives in config.

Only `public` loads open for quotes appear, and the carrier board's recency
window applies, so the page never advertises freight that has gone stale.

**Registering as a carrier requires choosing a plan.** `subscription_plan` is
required when `role=carrier` and **prohibited** for shippers — rejected rather
than ignored, because silently dropping a value the client sent is how
mismatched expectations survive to production. Shippers post loads free.

Picking the trial starts it immediately. Picking a paid plan **reserves it
pending payment** and grants nothing until the money is confirmed: signing up is
not a payment event, and treating it as one would hand the paid product to
anyone who filled in a form. The plan is applied inside the registration
transaction, after validation has already excluded the realistic failures — an
account created without the plan it asked for is worse than a sign-up that can
be retried.

A closed trial offer is checked separately from the plan list: `exists` only
proves the row is real and active, and a withdrawn offer must not be selectable
just because the row is still there.

**Carrier subscriptions.** Four plans, matching
freightmove.au/carriers-subscription: a two-month free trial, then monthly
($64.99), quarterly ($184.99) and annual ($699.90). The advertised per-month
saving is **computed from the prices**, never written into copy, so the claim on
the page cannot drift from the numbers beside it.

Buying is two steps. `checkout` records a **pending** period that entitles the
carrier to nothing; it only switches on when the money is confirmed.

`PaymentGateway` has two implementations, chosen by `FM_PAYMENT_GATEWAY`:

- **`manual`** (default) — the carrier pays by some arrangement off the platform
  and an admin confirms it from the payment queue. Works with no credentials,
  and stays useful afterwards for payments that arrive another way.
- **`paypal`** — PayPal Checkout, Orders v2. The same integration the previous
  site used: the imported history is 69 completed AUD captures with PayPal payer
  ids, so the merchant account already exists.

**Four rules make the PayPal path safe**, each covered by a test:

1. **The amount is never taken from the client.** The order is created for the
   plan's price, and after capture the amount PayPal reports is compared to that
   price *to the cent*, currency included. A mismatch is refused and logged
   rather than accepted — the money may have moved, and that needs a human.
2. **Webhooks are verified against PayPal** before they are acted on. An
   unverified webhook endpoint that activates subscriptions is a way to get the
   paid product by POSTing JSON. With no `PAYPAL_WEBHOOK_ID` configured they are
   refused outright rather than trusted.
3. **Capture is idempotent.** The browser return and the webhook race each
   other by design; whichever arrives second is a no-op. PayPal's
   `ORDER_ALREADY_CAPTURED` is treated as the success it describes.
4. **Failure leaves the subscription pending.** An unpaid subscription someone
   has to chase is recoverable; an unpaid subscription that granted access is
   not.

The webhook exists because the browser return is unreliable — a closed tab or a
lost signal must not mean someone pays and gets nothing.

Two references are kept, and they are not interchangeable: the **order** id on
`subscriptions.gateway_reference` (what the return leg and webhooks look up by)
and the **capture** id on the payment row. Conflating them broke retries.

Three rules are worth stating because each one was a bug first:

- **Pending never entitles.** `hasActiveSubscription()` read
  `status != 'cancelled'`, which counted an unpaid period — so anyone could hold
  the paid product for free by reserving a plan and never paying.
- **Cancelled still entitles, until the end date.** Cancelling means "do not
  renew", not "refund me by locking me out of what I paid for", which is what
  the carrier is told. Both rules live in `Subscription::ENTITLING_STATUSES`, so
  there is one definition of "entitled".
- **A new period starts the day after the current one ends**, at checkout *and*
  at confirmation. These were computed separately and disagreed: paying for a
  quarter with two months of trial left produced three months in total instead
  of five.

The trial runs **two months from the day it starts**. The previous platform set
every trial's end to the promotion's closing date instead, so eleven of ninety
legacy periods end before they begin, and anyone who started after March 2026
got a trial that had already expired.

**Completion and reviews.** The **shipper** closes a load out, not the
carrier: the carrier has an obvious interest in a job being marked done — it
ends the window in which a problem can be raised — so the party who received the
freight is the one who says it arrived. A carrier waiting on a quiet shipper can
chase them through the conversation on the load.

A review needs the job **completed** and the reviewer to have been one of the
two parties, once each (also a unique index on `(job_id, reviewer_id)`). The
person being reviewed is derived from the load, never sent by the client, so a
review cannot be aimed at someone uninvolved. There is no admin bypass: an admin
writing reviews would be the platform anonymously grading its own suppliers.

`user_profiles.rating` and `completed_jobs_count` are **derived** —
`ReputationService` recomputes them from reviews and completed loads on every
change. They are stored rather than computed on read only because the board and
every quote row display them. Before this existed, both columns were written
only by the factory, so the rating a shipper saw beside a carrier's quote was
invented. `php artisan reputations:recompute` rebuilds them from the records and
has a `--dry-run`.

A user with no reviews is **unrated** (null), not zero: "not rated yet" and
"rated zero" are opposite claims.

**Admin console.** Two things are deliberately absent from it.

*Role changes.* Nothing an admin can do through the API turns a shipper into an
admin. Role is the privilege boundary the whole authorisation layer rests on,
and an "edit user" form that happens to carry a role dropdown is how that
boundary gets crossed by accident. Granting admin should be a separate, audited
action if it is ever needed.

*Deletion, and editing other people's records.* Accounts carry loads and quotes
that the other side of a transaction depends on, and editing someone's freight
behind their back produces a record neither party recognises. Suspending stops
someone using the marketplace without erasing any of it; `GET /admin/jobs` is
read-only.

Suspending also **revokes every live token** — otherwise the session already
open in that person's browser keeps working until it expires. An admin cannot
suspend themselves (not recoverable from inside the app) or another admin (one
compromised admin account should not be able to disable everyone who could stop
it).

`GET /admin/overview` reports rates rather than only totals: a marketplace can
look busy on both sides and still be failing if the two never meet. It also
surfaces the migration gates, because the cost of switching either on is exactly
the number sitting beside it.

**Messaging.** A thread is always about one load, between that load's shipper
and one carrier. Two rules, both in `ConversationPolicy`:

- **The carrier must have quoted before a thread can exist.** This is the
  disintermediation guard. The platform withholds a carrier's phone and email
  until a quote is accepted; an open channel from any carrier to any shipper
  routes straight around that — "what's your number?" — and the subscription is
  the product. Quoting is a small commitment but a real one. If it proves too
  strict, that is the line to move, and the trade-off is on the record.
- **A conversation is private to its two participants — admins included.**
  Support reading customer messages should be a deliberate, audited feature, not
  a side effect of the `before()` hook every other policy uses.

Participants are stored **lowest user id first**. The unique index is on
`(job_id, participant_one_id, participant_two_id)`, which only prevents a
duplicate if the pair is written consistently; otherwise (job, A, B) and
(job, B, A) are two rows to the database and one conversation to everyone else.

A closed load leaves the thread readable and the composer disabled. Messages are
ordered by `created_at` **and `id`** — the timestamp has second resolution and a
chat is mostly messages seconds apart, so ordering on it alone leaves
consecutive lines arbitrary.

**Notifications.** Written by `app/Services/Notifier.php` at the seven
moments in `NotificationType` where someone either has a decision to make or
has been waiting on an answer. Three rules hold throughout:

- **The recipient is never the actor.** Nobody is told about something they
  just did, so each method takes its audience explicitly.
- **Notifying can never break the thing being notified about.** Every write is
  wrapped and its failure logged, and all of them happen *after* the
  transaction commits — a feed problem must not roll back a confirmed booking.
- **Losing quotes are read before they are updated.** Once the bulk update
  runs there is no way to tell "declined just now" from "declined last week",
  and only the former should be told.

A new message raises **at most one unread notification per conversation**. A
chat is a burst of short messages, and a feed that gains an entry for each of
them becomes a second, worse inbox. Once the recipient reads it, the next
message raises a fresh one.

There is no websocket. The client polls `unread-count` — count only, never the
rows — once a minute, and stops entirely while the tab is hidden.

### 403 responses carry the policy's reason

Several policies deny with a specific message — "an active subscription is
needed to quote", "quote on this load first" — because "you cannot do that"
leaves the caller with no idea which situation they are in. The API exception
handler previously overwrote every one of them with a generic line; it now
passes the policy's wording through, falling back to the generic string when a
policy simply returns false.

**Carrier profile and verification.** `PATCH /carrier/profile` takes a
whitelist; `verification_status`, `rating` and `completed_jobs_count` are all
fillable on the models behind it and none appear in the request, because all
three are claims the platform makes *about* a carrier rather than claims the
carrier makes about themselves. Changing an ABN, company name or insurer on an
already-verified profile sends it back to pending — the approval was granted
against those specific details.

`POST /carrier/documents` is the application's **first upload endpoint** and
implements the hardening checklist from `docs/11-security.md`: MIME validated
against file *contents* via finfo (not the extension or the declared type),
randomised storage names in a per-user folder on the private disk, a size cap,
and its own `uploads` limiter at 20/hour. There is no public URL — reading a
document back goes through `GET .../download`, behind auth and a policy, always
`Content-Disposition: attachment`. The stored path is never serialised.

Overall verification status is **derived** from the documents, not set by hand:
`VerificationService` recomputes it after every review. It will not promote on
its own — a human approves each document — and it withdraws a badge only on
positive evidence (a rejection or a lapsed document), so an admin who verified
someone by other means is not silently overruled.

**`GET /public/routes/{pickup}/{dropoff}`** takes two **suburb ids** and answers
**200 with `cached: false`** when the lane is not in the cache — the caller is a
form showing an optional distance hint, and a missing hint is not an error. A
hit increments the reuse counter. Nothing calls Google: populating a miss needs
a Distance Matrix key, and the legacy key is still to be rotated
(`docs/11-security.md`).

## 1b. Endpoints the legacy behaviour requires

From `docs/10-domain-rules.md`. These had no equivalent in the original spec and
must exist for the migrated data and the paid product to work. All but the
contact form are now built.

| Endpoint | Rule | Gap | Status |
| --- | --- | --- | --- |
| `GET /carrier/board` | Filters combine freely; recency window; ranked by relist then post date | R4, R7, G5 | ✅ |
| `POST /carrier/jobs/{job}/quotes` | Requires an **active subscription**, not merely the carrier role; one quote per carrier per load | R2, R3, G4 | ✅ built, enforcement off |
| `POST /shipper/jobs/{job}/relist` | Bump onto the board without masquerading as an edit | R5, G6 | ✅ |
| `POST /shipper/quotes/{quote}/accept` | Records the acceptance and moves the job on | — | ✅ |
| `GET /public/suburbs?q=` | Autocomplete over the 15,329 imported suburbs | — | ✅ |
| `GET /public/categories`, `/public/truck-types` | Seeded lookups, replacing the hardcoded lists in the Angular client | G2 | ✅ as `/public/taxonomy` |
| `GET /public/routes/{from}/{to}` | Cached distance for a suburb pair | R6, G3 | ✅ |
| `POST /contact` | The contact form already posts here | — | ✅ |

Board and quote listings must return **JSON**. The legacy app returned
pre-rendered HTML table fragments assembled by string concatenation inside
controllers; that is not reproduced.

## 2. Authentication Endpoints

### POST /auth/register
Register a new user as shipper or carrier

### POST /auth/login
Login and issue a Sanctum token

### POST /auth/logout
Invalidate token

### POST /auth/forgot-password
Send password reset email.

**The link must point at the Angular app.** Laravel's default builds it from the
`password.reset` *web* route, which this API-only application does not have, so
the endpoint threw `RouteNotFoundException` for any registered address — a 500
for exactly the people the feature is for, while unregistered addresses (the
only case the original test covered) looked fine.

`AppServiceProvider::definePasswordResetUrl()` now builds
`{FRONTEND_URL}/reset-password/{token}?email=…` instead, matching the Angular
route. `FRONTEND_URL` is the same key `config/cors.php` reads, so the allowed
origin and the emailed link cannot disagree.

### POST /auth/reset-password
Reset password

### GET /auth/me
Return current authenticated user profile

## 3. Public Endpoints

### GET /public/featured-jobs
List recently published jobs

### GET /public/blog-posts
List published blog posts

### GET /public/industries
List supported industries

### GET /public/routes
List popular routes

## 4. Shipper Endpoints

### GET /shipper/jobs
List shipper jobs

### POST /shipper/jobs
Create a freight job

### GET /shipper/jobs/{id}
Get job details

### PUT /shipper/jobs/{id}
Update job

### DELETE /shipper/jobs/{id}
Delete or cancel job

### GET /shipper/jobs/{id}/quotes
List quotes for a job

### POST /shipper/jobs/{id}/accept-quote
Accept a selected quote

### POST /shipper/jobs/{id}/review
Submit a review after completion

## 5. Carrier Endpoints

### GET /carrier/matches
List matching jobs for the carrier

### GET /carrier/quotes
List carrier quotes

### POST /carrier/quotes
Submit a quote

### PUT /carrier/quotes/{id}
Update quote

### GET /carrier/fleet
List carrier fleet vehicles

### POST /carrier/verification-documents
Upload verification documents

### GET /carrier/jobs
List accepted and completed jobs

## 6. Messaging Endpoints

### GET /messages/conversations
List conversations

### GET /messages/conversations/{id}
Get conversation thread

### POST /messages/conversations/{id}/messages
Send a message

### POST /messages/conversations/{id}/read
Mark messages as read

## 7. Notification Endpoints

### GET /notifications
List notifications

### PATCH /notifications/{id}/read
Mark notification as read

### PATCH /notifications/preferences
Update notification preferences

## 8. Admin Endpoints

### GET /admin/users
List users

### GET /admin/carriers
List carriers

### POST /admin/carriers/{id}/approve
Approve carrier verification

### GET /admin/jobs
List all jobs

### GET /admin/quotes
List all quotes

### GET /admin/analytics
Get marketplace analytics

## 9. Response Examples

### Success Response
```json
{
  "success": true,
  "data": {},
  "message": "Operation completed successfully"
}
```

### Error Response
```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {}
}
```
