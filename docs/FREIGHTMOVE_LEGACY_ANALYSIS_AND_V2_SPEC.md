# FreightMove — Legacy Analysis & V2 Build Spec

> Source: analysis of the existing Laravel codebase for freightmove.au (uploaded ZIP, no `vendor/`/`node_modules/`).
> Purpose: give an AI coding assistant (Claude Code, Copilot, etc.) or a human developer full context on what the old app did, so the new build reflects the same business logic without repeating the old app's architecture problems.

---

## 1. What the app is

A two-sided **freight marketplace** for the Australian trucking/transport industry:

- **Shippers** post loads (what needs moving, from where, to where, dimensions, category, truck type needed).
- **Carriers** browse posted loads and submit price quotes.
- **Admin** (`/myadmin`) manages shippers, carriers, loads, blog content, subscriptions, and payment records.
- **Carriers pay a subscription** (via PayPal) to access the platform / quote on loads.

Secondary features: company blog, static SEO landing pages per freight category/route (e.g. `/boat-transport`, `/freight-sydney-to-adelaide`), contact forms, and email notifications throughout.

---

## 2. Legacy architecture pattern (what NOT to repeat)

The old app is **not** built around Eloquent models. Only the default `User.php` scaffold exists and it's unused for real logic. All actual data access is raw `DB::table(...)` calls **directly inside controller methods**. No migrations define the real schema — the live database tables were created outside of Laravel's migration system entirely.

Consequences worth knowing about (all fixed in the V2 spec below):

- **Primary keys are `rand()`** (a random PHP int), not auto-increment or UUID — collision-prone and not sortable.
- **Business logic duplicated across near-identical branches** — e.g. freight search has 7 hand-written if/else blocks for every combination of 3 optional filters, and PayPal checkout has 4 near-identical `processTransaction1()`...`processTransaction4()` methods, one per subscription tier, with the tier looked up by a **hardcoded record ID** (`where('id','1583243636')`).
- **HTML built as PHP string concatenation inside controllers** — both AJAX table-row fragments and outbound transactional emails are raw HTML strings assembled in PHP, not Blade views/mailables.
- **Emails sent two ways inconsistently** — sometimes via Laravel `Mail::to()->send()` with a Mailable class, sometimes via a manual Guzzle POST to the SendGrid HTTP API with the API key pulled from config but the HTML body hardcoded in the controller.
- **Session-based auth without Laravel's `Auth` facade** — login just does `$request->session()->put('id', $user->id)`; no guards, no policies, no `Auth::user()`.
- **Shippers and carriers share one `shipper` table**, differentiated only by a `ship_car` flag (`1` = shipper, `2` = carrier).
- **A live Google Maps API key is hardcoded** directly in a controller file rather than read from `.env`/config.
- **A hardcoded master password bypass exists in shipper login** (`loginAuth::ShipperLogin`) — a specific literal password string logs into *any* shipper account regardless of their real password. This must not exist in V2. Flagging this here so it isn't accidentally reproduced or missed during any data/logic migration.
- Several admin routes reference controllers that don't exist in the codebase at all (`basePrice`, `algorithm`, `quoteMaster`, `booking`, `timeSlot*`, `promoCode`, `publicHoliday`, `mediaLibrary`) — these appear to be leftover routes from a different project template and would 500 if hit. Don't treat these as real features to rebuild unless you know otherwise.

---

## 3. Inferred data model (legacy)

No schema file exists, so this is reconstructed from every column referenced across controllers. Treat field names as a strong guide, not a guarantee of exact types.

### `shipper` (holds BOTH shippers and carriers)
| Column | Notes |
|---|---|
| id | `rand()` int, PK |
| ship_car | `1` = shipper, `2` = carrier |
| first_name, last_name | |
| email_id | login identifier |
| phone_no | |
| password | bcrypt hash |
| shipper_type | free-text/select, meaning not fully clear from controllers |
| street_address, city, state, zip, country | profile address |
| profile_img | filename, stored in `public/images/shipper` |
| business_profile, company_name, abn_number | business tab fields |
| date_created, date_updated | |
| created_by, updated_by | md5 of the creating session's user id (odd pattern, likely can drop in V2) |

### `load_master`
| Column | Notes |
|---|---|
| id | `rand()` int, PK |
| shipper_id | FK → shipper.id |
| short_desc, load_dec | title + long description |
| pickup_suburb, dropoff_suburb | FK → suburb_master.id |
| pickup_state, dropoff_state | denormalized copy of state, used for search filters |
| categories | comma-separated string of category IDs (not a pivot table) |
| truck_type | comma-separated string of truck type IDs |
| quantity, length, width, height, weight | |
| availability | int enum: `1`=ASAP, `2`=Ready now, `3`=Available From, `4`=Planning & Budgeting |
| readyon | date |
| image | uploaded file, stored in `public/images/load` |
| date_created, date_updated | `date_updated` also used to "bump"/relist a load |

### `load_quotation`
| Column | Notes |
|---|---|
| id | `rand()` int, PK |
| carrier_id | FK → shipper.id (where ship_car=2) |
| load_id | FK → load_master.id |
| price_quoted | |
| notes | |
| date_created | |
| Uniqueness rule enforced in code (not DB constraint): one quote per (carrier_id, load_id) |

### `suburb_master`
| Column | Notes |
|---|---|
| id | PK |
| suburb, state | Australian suburb reference data, used for both location pickers and autocomplete search |

### `distance_calculator`
| Column | Notes |
|---|---|
| id | `rand()` int |
| pickup, dropoff | suburb_master IDs |
| distance, time_duration | cached text results from Google Distance Matrix API |
| count | hit counter, incremented each time the same route is reused |
| date_created | |
Acts as a cache so the Google Maps API isn't called for every duplicate pickup/dropoff pair.

### `subscription_master`
| Column | Notes |
|---|---|
| id | `rand()` int — looked up by literal hardcoded ID per tier in PayPalController |
| price | AUD, used directly in PayPal order creation |
(Only 4 tiers appear to exist based on `processTransaction1`–`4`.)

### `blog_master`, `media_library` — CMS-style tables for the blog; not fully inspected in this pass, worth a closer read of `blogMaster.php` if the new site keeps the blog.

---

## 4. Core business logic flows (legacy behavior to preserve)

### Load posting
1. Shipper submits form → validate required fields (pickup/dropoff suburb, description, category, truck type).
2. If pickup/dropoff suburb pair not seen before, call Google Distance Matrix API live and cache result in `distance_calculator`; otherwise reuse cached value and increment its counter.
3. Insert load row; optional image upload.
4. Send an admin/shipper notification email (currently via a raw SendGrid API POST with an inline HTML template).

### Freight search/browse
1. Optional filters: pickup state, dropoff state, availability status — any combination.
2. Only loads updated within the **last 7 days** are shown by default.
3. Results rendered as an HTML table fragment returned directly from the controller (AJAX-loaded into the page), not JSON.

### Carrier quoting
1. Carrier submits a price + notes on a specific load.
2. Reject if that carrier already quoted that load (one quote per carrier per load).
3. Insert quote; email the shipper with quote details.

### Auth
- Simple session flag (`session('id')`) shared by the shipper/admin login paths — no role-based guards.
- Registration requires reCAPTCHA v3 (score ≥ 0.5) before insert.
- Password reset generates a random 8-char token, emails it, no expiry logic visible.

### Payments (carrier subscriptions)
- PayPal order created via `srmklive/paypal` package.
- Tier price looked up by hardcoded subscription_master record ID per tier (4 tiers).
- Return/cancel/success routes handle PayPal's redirect flow; success presumably updates the shipper's subscription status (worth re-checking `successTransaction` methods if payment logic needs full preservation).

---

## 5. Suggested V2 architecture

### Stack decisions
- Keep Laravel (your team's expertise transfers directly), but build it the "framework-native" way this version skipped:
  - **Eloquent models with relationships**, not raw `DB::table()`.
  - **Migrations as the actual source of truth** for schema — no more hand-created tables.
  - **Form Request classes** for validation instead of inline `$request->validate()` blocks duplicated per method.
  - **Policies/Gates** for authorization (shipper vs carrier vs admin) instead of ad hoc `if ($ship_car == 2)` checks scattered through controllers.
  - **Laravel's `Auth` facade + guards**, ideally two guards (`shipper`, `admin`) or a `role` enum on a single `users` table, instead of a bare session key.
  - **Queued jobs** for anything calling an external API or sending email (distance lookups, SendGrid/notification emails) so requests like "post a load" don't block on network calls.
  - **Mailables + Blade mail views** instead of PHP-string HTML emails and manual SendGrid POSTs. Pick one email-sending path (Laravel Mail with your provider as the mailer driver) instead of two inconsistent methods.

### Suggested schema (V2)

```
users
  id (bigint, PK, auto-increment)
  role (enum: shipper, carrier, admin)
  first_name, last_name
  email (unique)
  phone
  password (hashed)
  email_verified_at
  timestamps

shipper_profiles  (1:1 with users where role=shipper, or merge into users if you prefer one table)
  user_id (FK)
  street_address, city, state, zip, country
  profile_image_path
  company_name, abn_number, business_profile
  timestamps

carrier_subscriptions
  id
  user_id (FK -> users, role=carrier)
  subscription_plan_id (FK)
  status (enum: active, expired, cancelled)
  starts_at, ends_at
  timestamps

subscription_plans
  id
  name
  price
  duration_days
  timestamps

suburbs
  id
  name, state
  (consider lat/lng columns to enable real distance calculation without hitting Google Maps every time, or to switch providers later)

loads
  id (bigint, PK)
  shipper_id (FK -> users)
  title (short_desc)
  description (load_dec)
  pickup_suburb_id (FK -> suburbs)
  dropoff_suburb_id (FK -> suburbs)
  quantity, length, width, height, weight
  availability (enum: asap, ready_now, available_from, planning)
  ready_on (date, nullable)
  status (enum: active, relisted, closed) -- explicit instead of relying on date_updated
  timestamps

load_categories / truck_types   <- proper pivot tables (load_category, load_truck_type)
  instead of comma-separated strings in load_master

categories
  id, name

truck_types
  id, name

load_images
  id, load_id (FK), path
  (supports multiple images per load, unlike the legacy single-image field)

load_quotes
  id
  load_id (FK)
  carrier_id (FK -> users)
  price_quoted
  notes
  unique constraint on (load_id, carrier_id)
  timestamps

route_distances   <- cache table, same idea as distance_calculator but keyed by suburb IDs with a unique constraint
  id
  pickup_suburb_id, dropoff_suburb_id (unique together)
  distance_text, duration_text, distance_meters (nullable, for real sorting/filtering)
  lookup_count
  timestamps

blog_posts
  id, title, slug, body, featured_image, published_at, timestamps
```

### Suggested API/controller shape
- RESTful resource controllers (`LoadController`, `QuoteController`, `SubscriptionController`) instead of one-off single-purpose controllers per admin table.
- Return JSON from AJAX endpoints (search/filter) and let the frontend render — decouples backend from HTML string-building, and sets you up to add a proper SPA/mobile client later if wanted.
- Centralize the freight search filter logic into a single query builder method using `when()` clauses instead of 7 branching if/else blocks:
  ```php
  Load::query()
      ->when($pickupStates, fn($q) => $q->whereIn('pickup_state', $pickupStates))
      ->when($dropoffStates, fn($q) => $q->whereIn('dropoff_state', $dropoffStates))
      ->when($availability, fn($q) => $q->whereIn('availability', $availability))
      ->where('updated_at', '>=', now()->subDays(7))
      ->get();
  ```

### Things to explicitly re-decide, not just port over
- Whether shippers/carriers should stay one table with a role flag, or split into two tables/models — recommend one `users` table with a `role` enum plus a role-specific profile table, since it plays nicer with a single `Auth` guard.
- Whether comma-separated `categories`/`truck_type` become real many-to-many pivot tables (recommended — enables filtering/reporting that's painful with CSV strings).
- Whether the 7-day "recent loads only" search window should become a configurable setting rather than hardcoded.
- Whether subscription tiers should be looked up by a slug/name (`'basic'`, `'pro'`) rather than a raw numeric ID, so PayPal logic doesn't break if a plan record is ever recreated.

---

## 6. Open items to verify against the live legacy code before cutover
- Full contents of `blogMaster.php`, `shipperMaster.php`, `profileMaster.php`, `addSubscription.php`, `contactCont.php`, `StoreController.php` were not all fully read line-by-line in this pass — review these if the blog/admin/contact-form logic needs 1:1 behavior parity.
- `PayPalController`'s `successTransaction` handlers (what actually happens in the DB after a successful payment — does it activate the subscription record, update `shipper`, etc.) should be re-checked in full before building V2's payment webhook/success handling.
- Confirm whether the hardcoded shipper-login backdoor password is still active on the current production site — if so, that's a live security issue independent of the rebuild and worth addressing immediately, not just fixing in V2.
