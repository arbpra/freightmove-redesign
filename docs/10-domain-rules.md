# Domain Rules — Legacy Behaviour Merged with V2

The authoritative statement of how FreightMove actually works, reconciling three
sources:

1. **`FREIGHTMOVE_LEGACY_ANALYSIS_AND_V2_SPEC.md`** — analysis of the old
   Laravel codebase (what the code did).
2. **The live `old_freightmove` database** — 536 accounts, 103 loads, 143
   quotes, 15,329 suburbs (what the data actually contains).
3. **What is built in this repo** — see `08-development-roadmap.md`.

**Where the spec and the data disagree, the data wins.** The spec was written by
reading controllers; the data is what must migrate. Two such disagreements are
recorded below.

---

## 1. What the platform is

A two-sided Australian freight marketplace.

- **Shippers** post loads: what is moving, from where to where, dimensions,
  category, and the truck types that could carry it.
- **Carriers** browse posted loads and submit a price quote with notes.
- **Carriers pay a recurring subscription** for platform access.
- **Admin** manages accounts, loads, blog content, subscriptions and payments.

Supporting surfaces: a blog, SEO landing pages per freight category and per
route (`/boat-transport`, `/freight-sydney-to-adelaide`), contact forms, and
transactional email throughout.

---

## 2. Legacy semantics decoded

Values the old schema stored as bare integers, with no lookup table.

### `load_master.availability`

| Code | Meaning | Rows in live data |
| --- | --- | --- |
| 1 | ASAP | 33 |
| 2 | Ready now | 35 |
| 3 | Available from *(pairs with `readyon`)* | 25 |
| 4 | Planning & budgeting | 10 |

All four are in active use. This is a **shipper's urgency signal**, distinct
from the job's lifecycle status, and carriers filter on it.

### `shipper.ship_car`

`1` = shipper, `2` = carrier. Confirmed behaviourally, not assumed: accounts
with `1` posted 93 of 100 loads; accounts with `2` submitted 126 of 127 quotes.

### `shipper.shipper_type`

`1` = individual, `2` = business. Type `2` accounts carry company names and ABNs
at roughly ten times the rate of type `1`.

### `subscription_details.subscription_type`

A 1-based index into the plan list, **not** a plan id. Value `4` is the legacy
free tier and maps to no plan record.

---

## 3. Where the spec and the data disagree

### Categories and truck types hold **names**, not IDs

The spec states these columns are "comma-separated string of category IDs". They
are not. The live data holds comma-separated **display names**:

```
categories: "Trailers to be carried,Trailers to be Towed"
truck_type: "Bobtail Prime Mover,Drop Deck"
```

There is **no `categories` or `truck_types` master table** anywhere in the legacy
database — confirmed by querying `information_schema`. So there are no IDs to
resolve, and V2 must seed its own lookup tables from the distinct values found
in the data.

### Subscription tiers: 4 code paths, 3 plan records

The spec infers four tiers from `processTransaction1()`–`4()`. The data holds
**three** rows in `subscription_master` (Monthly $64.99, Quarterly $184.99,
Annual $699.90) plus a fourth pseudo-tier, "Free", that exists only as
`subscription_type = 4` on subscription periods.

---

## 4. Core business rules to preserve

These come from the legacy application and are **not optional** — the migrated
data depends on them.

### R1 — A load can have many categories and many truck types

**65% of live loads carry more than one truck type** (67 of 103); 12 carry more
than one category. A single-value column silently discards the rest.

### R2 — One quote per carrier per load

Enforced in legacy code, never as a database constraint. V2 enforces it with a
unique index so it cannot be bypassed.

### R3 — Carriers need an active subscription to quote

Platform access is the paid product. Quoting must check for a current
subscription, not merely the carrier role.

### R4 — The load board shows recent loads only

Legacy defaults to loads touched within the **last 7 days**. Should become a
configurable setting rather than a hardcoded literal.

### R5 — Relisting bumps a load back onto the board

Legacy achieves this by touching `date_updated`. V2 should make it explicit —
an action with its own timestamp — rather than overloading the update column,
so "edited" and "relisted" stay distinguishable.

### R6 — Route distances are cached, not recomputed

A pickup/dropoff pair is looked up once via Google Distance Matrix, cached, and
a hit counter incremented on reuse. 663 cached routes exist. Keyed by suburb id
pair with a unique constraint.

### R7 — Search filters combine freely

Pickup state, dropoff state and availability, in any combination. Built as one
query with `when()` clauses, never branching if/else per combination.

---

## 5. Reconciliation — legacy → what is built

| Legacy concept | V2 destination | Status |
| --- | --- | --- |
| `shipper` (both roles) | `users` + `user_profiles` + `carriers` | ✅ Built; `role` enum |
| `load_master` | `freight_jobs` | ✅ Built |
| `load_quotation` | `job_quotes` | ✅ Built, unique (job, carrier) |
| `suburb_master` | `suburbs` | ✅ Built, 15,329 rows |
| `subscription_master` | `subscription_plans` | ✅ Built |
| `subscription_details` | `subscriptions` | ✅ Built |
| `paypal_transaction` | `subscription_payments` | ✅ Built |
| `blog_master` | `blog_posts` | ✅ Built (not shown on site) |
| `availability` (1–4) | `freight_jobs.availability` enum | ✅ **Closed** (G1) |
| `categories` (multi) | `categories` + `category_freight_job` | ✅ **Closed** (G2) |
| `truck_type` (multi) | `truck_types` + `freight_job_truck_type` | ✅ **Closed** (G2) |
| `distance_calculator` | `route_distances` | ✅ **Closed** (G3) |
| Subscription gates quoting | `JobQuotePolicy` + `User::canQuote()` | ⚙️ **Built, off by default** — see G4 |
| 7-day board window | `scopeRecent`, configurable | ✅ **Closed** (G5) |
| Relist / bump | `freight_jobs.relisted_at` + `POST .../relist` | ✅ **Closed** (G6), with a cooldown legacy never had |
| `load_img` (single) | `images_json` (array) | ✅ Improved |
| `rand()` primary keys | auto-increment + `legacy_id` | ✅ Improved |
| Raw `DB::table()` in controllers | Eloquent + policies + form requests | ✅ Improved |
| Session flag auth | Sanctum tokens + role guards | ✅ Improved |
| Master-password backdoor | — | ✅ Never reproduced — see S1 |

---

## 6. Gaps to close

Ordered by how much data they affect.

### ~~G1 — `availability` has no column~~ ✅ closed

`freight_jobs.availability` holds the enum; `LoadAvailability::fromLegacy()`
maps codes 1–4 and returns null for anything unrecognised rather than
guessing. Re-imported: 31 ASAP, 34 ready now, 25 available from, 10 planning
— all 100 loads.

### ~~G2 — Multi-select categories and truck types are collapsed~~ ✅ closed

`categories` (13) and `truck_types` (19) are seeded by `FreightTaxonomySeeder`
from the distinct values in the live data, with `category_freight_job` and
`freight_job_truck_type` pivots.

Re-import reconciles exactly against the source: **351 truck-type values and
114 category values in, 351 and 114 out.** One load lists 18 truck types — the
old single column was keeping one of them.

The importer reports any value missing from the seeded vocabulary instead of
dropping it silently. That guard immediately found `Refrigerated`, which the
first extraction sweep had missed.

`load_category` and `trailer_type_required` remain as denormalised primary
values for compact list rows; the pivots are the source of truth.

### ~~G3 — No route distance cache~~ ✅ closed

`route_distances` keys on a unique `(pickup_suburb_id, dropoff_suburb_id)` pair
with a reuse counter. **All 663 legacy rows import**, and every one parses into
canonical units; the 320 recorded cache hits come across too.

The parsing was the work. Legacy stored whatever the Distance Matrix API
returned, which turned out to be two different things:

| Shape | Rows | Example |
| --- | --- | --- |
| `N km` | 642 | `3,616 km` |
| `N.N km` | 11 | `21.1 km` |
| `N m` | 8 | `1 m` |
| bare integer (metres) | 2 | `713686` |

Durations vary the same way — `1 day 15 hours`, `17 hours 5 mins`,
`1 hour 3 min`, `1 min`, and two bare second counts — with inconsistent
pluralisation, so the parser sums every `<number> <unit>` pair rather than
matching each phrasing. The provider's own text is kept alongside the numbers
for display, since `3,616 km` was already rounded before we ever saw it. The two
bare integers are *not* kept as text: they would render as "713686" to a user.

Served by `GET /public/routes/{pickup}/{dropoff}`, which is **directional** —
legacy holds both directions for 20 pairs and the figures differ, so B→A is not
served as an answer for A→B.

Still open: `lat`/`lng` on `suburbs`, so an uncached lane could be estimated
without a third-party call. `suburb_master` holds no coordinates, so that needs
an external dataset.

### G4 — Quoting does not check subscription ⚙️ built, **enforcement off**

`JobQuotePolicy::create()` checks `User::canQuote()`, which honours two settings
in `config/freightmove.php`. Both paths are covered by tests.

**It defaults to off, and that is a decision waiting on you.** The migrated data:

| | |
| --- | --- |
| Carriers imported | 291 |
| Holding a subscription that has not expired | **2** |
| Who ever held one | 49 |

Switching `FM_REQUIRE_SUBSCRIPTION_TO_QUOTE=true` today would lock **289 of 291
carriers** out of the marketplace. Turn it on once the subscription flow is live
and carriers have had a chance to renew.

`FM_LEGACY_QUOTING_GRACE_UNTIL=YYYY-MM-DD` softens the cut-over: with
enforcement on, carriers carrying a `legacy_id` keep quoting until that date.

### ~~G5 — No recency window on the load board~~ ✅ closed

`FreightJob::scopeRecent()` applies `FM_BOARD_RECENCY_DAYS` (default 7, the
legacy value); 0 disables it. The board reports the active window in its
response so the UI can explain why older loads are absent.

Measured from `relisted_at` when present, so a bump genuinely returns a load
to the board rather than only reordering it.

### ~~G6 — No relist action~~ ✅ closed

`freight_jobs.relisted_at` drives both the board's recency window and its
ordering, and `POST /shipper/jobs/{job}/relist` is the only thing that sets it.
An edit deliberately does not bump — that separation is the whole point of R5,
and there is a test pinning it.

Allowed only while the load is `published`, `matched` or `quoted`: a draft is
not on the board to be bumped, and a booked or cancelled load must never
reappear on it.

One addition legacy did not have: a cooldown, `FM_RELIST_COOLDOWN_HOURS`,
default 24. Legacy's bump-by-edit had no limit at all, so a shipper could hold
the top of the board indefinitely at every other shipper's expense. Inside the
window the endpoint answers 429 and says when the next bump is due. Set it to 0
to restore the legacy free-for-all.

---

### R8 — Subscription type codes, and the trial that expired before it started

`subscription_details.subscription_type` is a small code, **not** a plan id, and
not an index into the plan table either. Decoded from what each cohort actually
paid:

| Code | Plan | Paid | Periods |
| --- | --- | --- | --- |
| 1 | Monthly | $64.99 | 64 |
| 2 | Quarterly | $184.99 | 6 |
| 3 | Annual | $699.90 | 1 |
| 4 | Free trial | — (`paypal_trans = 'Free'`) | 19 |

The importer originally read the code as a 1-based index into the plan list.
That list arrives ordered by primary key — monthly, annual, quarterly — so codes
2 and 3 were swapped: **six carriers who paid $184.99 were recorded on the
$699.90 plan**, and one who paid $699.90 on the $184.99 one. Plans now carry a
`code` and are addressed by name.

The free trial has **no row in `subscription_master`** at all, despite being on
the pricing page and despite nineteen carriers having been given one. It is
seeded as a real plan in V2.

**The legacy trial never gave anyone two months.** `end_to` was set to the
promotion's closing date (2026-03-31) for every trial rather than to two months
after the start, so:

- eleven of ninety legacy periods **end before they begin**;
- anyone starting a trial after 31 March 2026 — and the data shows trials handed
  out in July 2026 — received one that had already expired.

Legacy dates are imported exactly as recorded, because rewriting them would hide
the problem. New trials run two months from the day they start.

---

## 7. Security note

### S1 — Legacy master-password backdoor

The old `loginAuth::ShipperLogin` accepts a hardcoded literal password that
signs into **any** shipper account regardless of the real password.

- It is **not** reproduced in V2. Authentication goes through
  `Hash::check` against the stored bcrypt hash, with no bypass.
- **This remains a live issue on the current production site** until that site
  is retired. It is independent of the rebuild and worth addressing now.

Also not carried over: a Google Maps API key hardcoded in a controller. Any key
in V2 belongs in `.env`.

---

## 8. Deliberate departures from the legacy design

Changes that alter *implementation*, never the business rule.

| Change | Reason |
| --- | --- |
| `freight_jobs`, not `loads` | "Job" is the term used throughout this codebase and its docs; `jobs` is taken by Laravel's queue table |
| Richer job lifecycle (`draft` → `published` → … → `completed`) | Legacy had no status column at all; state was inferred from dates |
| Quote statuses and acceptance records | Legacy stored no accept/decline state, so quote outcomes were unrecoverable |
| Reviews, messaging, notifications, tracking | New capability, no legacy equivalent |
| Sanctum tokens over session flags | Required by the decoupled Angular client |

### Still open

- The **freight category vocabulary shown on the marketing site does not match
  the live data.** The homepage advertises Heavy Haulage, Livestock, Grain &
  Hay, Liquid Tanker; customers actually select Machinery (Mobile), General Part
  Load, Trucks or Prime Movers, Car. Seeding `categories` from real data (G2)
  forces this decision.
- Whether admin lives at `/myadmin` (legacy) or `/admin` (current).
- Whether the blog returns at launch — the section is built but not rendered.
