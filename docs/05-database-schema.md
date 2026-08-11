# Database Schema

## 1. Core Design Principles

- Use relational tables for users, jobs, quotes, messages, reviews, and payments
- Keep audit fields on core entities: created_at, updated_at, created_by, updated_by
- Support soft deletes where appropriate for moderation and recovery
- Use status enums for job and quote lifecycle tracking

## 2. Core Tables

### users
- id (PK)
- name
- email
- password_hash
- phone
- role (guest|shipper|carrier|admin)
- status (pending|active|suspended|blocked)
- email_verified_at
- avatar_url
- timezone
- locale
- created_at
- updated_at

### user_profiles
- id (PK)
- user_id (FK -> users.id)
- company_name
- abn_acn
- business_type
- address_line_1
- address_line_2
- city
- state
- postal_code
- country
- bio
- verification_status (unverified|pending|verified|rejected)
- rating
- completed_jobs_count
- created_at
- updated_at

### carriers
- id (PK)
- user_id (FK -> users.id)
- fleet_size
- service_radius_km
- preferred_regions
- insurance_provider
- insurance_policy_number
- operating_since
- created_at
- updated_at

### vehicle_types
- id (PK)
- carrier_id (FK -> carriers.id)
- name
- trailer_type
- max_weight_tons
- dimensions
- is_active
- created_at
- updated_at

### freight_jobs
- id (PK)
- shipper_id (FK -> users.id)
- title
- description
- pickup_location
- delivery_location
- pickup_date
- delivery_date
- load_category
- weight_tons
- vehicle_type_required
- trailer_type_required
- budget_min
- budget_max
- status
- visibility
- images_json
- documents_json
- created_at
- updated_at

### job_quotes
- id (PK)
- job_id (FK -> freight_jobs.id)
- carrier_id (FK -> users.id)
- amount
- currency
- estimated_delivery_date
- notes
- status (pending|accepted|rejected|expired)
- match_score
- created_at
- updated_at

### job_acceptances
- id (PK)
- job_id (FK -> freight_jobs.id)
- quote_id (FK -> job_quotes.id)
- carrier_id (FK -> users.id)
- shipper_id (FK -> users.id)
- accepted_at
- created_at
- updated_at

### job_tracking
- id (PK)
- job_id (FK -> freight_jobs.id)
- current_status
- last_location
- eta
- updated_at

### reviews
- id (PK)
- job_id (FK -> freight_jobs.id)
- reviewer_id (FK -> users.id)
- reviewed_user_id (FK -> users.id)
- rating
- comment
- created_at
- updated_at

### conversations
- id (PK)
- job_id (FK -> freight_jobs.id)
- participant_one_id (FK -> users.id)
- participant_two_id (FK -> users.id)
- created_at
- updated_at

### messages
- id (PK)
- conversation_id (FK -> conversations.id)
- sender_id (FK -> users.id)
- message_type (text|image|document)
- body
- attachment_path
- read_at
- created_at
- updated_at

### notifications
- id (PK)
- user_id (FK -> users.id)
- type
- title
- body
- is_read
- related_type
- related_id
- created_at
- updated_at

### verification_documents
- id (PK)
- user_id (FK -> users.id)
- document_type
- file_path
- status (pending|approved|rejected)
- reviewed_by
- review_note
- created_at
- updated_at

### payments
- id (PK)
- job_id (FK -> freight_jobs.id)
- payer_id (FK -> users.id)
- payee_id (FK -> users.id)
- amount
- currency
- status
- gateway_reference
- created_at
- updated_at

### blog_posts
- id (PK)
- title
- slug
- excerpt
- content
- featured_image
- author_id
- status
- published_at
- created_at
- updated_at

### support_tickets
- id (PK)
- user_id (FK -> users.id)
- subject
- message
- status
- priority
- created_at
- updated_at

## 2a. Tables added for the legacy migration

Added while importing the pre-launch database. See
`docs/09-legacy-data-migration.md` for the mapping and the re-import procedure.

### legacy_id columns

`users`, `freight_jobs`, `job_quotes` and `blog_posts` each carry a nullable,
unique `legacy_id` holding the old platform's primary key (a random numeric
string, not an integer). The importer upserts against it, which is what makes
re-importing a fresher dump at go-live update rows instead of duplicating them.
Rows created natively have a null `legacy_id` and are never touched by it.

### suburbs
Australian reference data (~15,300 rows) carried over from `suburb_master`.
The legacy schema stored only a suburb id on each load; new jobs store resolved
location strings, so this table powers autocomplete and let the importer turn
those ids back into place names.

- id (PK)
- legacy_id (unique, nullable)
- name
- state
- created_at, updated_at
- index: (state, name), name

### route_distances
Cached driving distance between two suburbs, carried over from the legacy
`distance_calculator` (663 rows, all imported). Each row is a Google Distance
Matrix call already paid for; 165 of them have been served from cache at least
once, 320 hits in total.

- id (PK)
- legacy_id (unique, nullable)
- pickup_suburb_id, dropoff_suburb_id (FK -> suburbs, **unique together**)
- distance_metres, duration_seconds (nullable, canonical units)
- distance_text, duration_text (nullable, the provider's own wording)
- lookups, last_used_at
- created_at, updated_at

Two things differ from the legacy table:

1. **Numbers are stored as numbers.** Legacy kept whatever the API returned —
   mostly display strings (`3,616 km`, `1 day 15 hours`), and for two rows the
   raw metre/second integers from an earlier code path. Neither sorts nor
   filters. The importer parses both shapes into canonical units and keeps the
   original text alongside for display, which is also an honest record of the
   precision: `3,616 km` was rounded before we ever saw it. A bare integer is
   *not* kept as text — it would render as "713686" to a user.
2. **The pair is a real unique constraint on foreign keys**, so a route can no
   longer point at a suburb that has been removed.

The cache is **directional**. Legacy holds both directions separately for 20
pairs and the figures differ, so A→B is not served as an answer for B→A.

### verification_documents and user_profiles — extended

`verification_documents` gained `original_name`, `mime_type`, `size_bytes`,
`reviewed_at` and `expires_at`. A reviewer opening the queue needs to know what
a file claims to be before opening it, and an audit later needs to know what was
actually stored. Stored filenames are randomised, so `original_name` is the only
human-readable handle on a document. `expires_at` matters because a certificate
of currency lapses — one that was valid in March is not evidence of anything in
December, and an expired document stops counting towards verification.

`user_profiles` gained `verification_submitted_at`, `verified_at` and
`verification_note`. It previously had a status with nothing to say when it
changed or why, which makes a rejection impossible to explain to the carrier.

Document files live on the `local` disk, which roots at `storage/app/private`
and is not reachable over HTTP by any path. `file_path` is hidden from every
serialisation.

### reviews, and the derived reputation columns

`reviews` was in the schema from the start; nothing wrote to it. It now carries
one review per direction per completed load, enforced by the unique index on
`(job_id, reviewer_id)`.

`user_profiles.rating` and `user_profiles.completed_jobs_count` are **derived
columns**, not facts anyone asserts. `ReputationService` recomputes them from
reviews and completed loads whenever either changes; nothing else may write
them, and the carrier profile form excludes both. They are stored rather than
computed on read because the carrier board and every quote row display them, and
a subquery per row is the N+1 they exist to avoid.

Until this landed both columns were only ever written by the factory — seeded
carriers claimed 82 to 180 completed jobs and ratings around 4.0, with twelve
review rows and six completed loads in the whole database. No **migrated**
account was affected (none had a rating), but the mechanism was missing
entirely. `php artisan reputations:recompute` rebuilds from the records.

### contact_messages
Enquiries from the public contact form.

- id (PK)
- name, email, phone, role, subject, message
- ip_address, user_agent (for tracing abuse; not shown to staff)
- user_id (FK -> users.id, nullable — set when the sender was signed in)
- notified_at (when the team email actually went), handled_at
- created_at, updated_at
- index: (handled_at, created_at), email

Stored rather than only emailed. Mail is the part most likely to fail — an
expired credential, a provider outage, a spam filter — and an enquiry that only
ever existed inside a failed send is a lost customer. The row is written first
and the notification is best effort on top of it, with `notified_at` recording
whether it went.

### subscription_plans
- id (PK), legacy_id (unique, nullable)
- name, price, currency, interval_months, is_active
- created_at, updated_at

### subscriptions
- id (PK), legacy_id (unique, nullable)
- user_id (FK -> users.id)
- subscription_plan_id (FK -> subscription_plans.id, nullable)
- status, starts_on, ends_on, gateway_reference
- created_at, updated_at
- index: (user_id, status), ends_on

### subscription_payments
- id (PK), legacy_id (unique, nullable)
- user_id (FK -> users.id, nullable)
- subscription_plan_id (FK -> subscription_plans.id, nullable)
- gateway, gateway_reference, payer_name, payer_email
- amount, currency, status, paid_at
- created_at, updated_at
- index: user_id, paid_at

> These three exist because the schema above models only **per-job** `payments`
> and had nowhere to record who is on a paid plan or when their access lapses.
> Without them the legacy billing history (90 subscription periods, 69 completed
> PayPal payments) would have been dropped.

## 2b. Required to preserve legacy behaviour — not yet built

Derived from `FREIGHTMOVE_LEGACY_ANALYSIS_AND_V2_SPEC.md` reconciled against the
live `old_freightmove` data. Rationale and gap numbers are in
`docs/10-domain-rules.md`; these are the schema changes each one needs.

### freight_jobs.availability — **G1**
Add `availability` enum (`asap`, `ready_now`, `available_from`, `planning`),
nullable.

The legacy `load_master.availability` int (1–4) is the shipper's urgency signal
and is distinct from the job's lifecycle `status`. All four values are in live
use across 103 loads, and all of them were lost on import because no column
exists to receive them.

### categories + truck_types with pivots — **G2**
```
categories            id, name, slug, is_active, timestamps
truck_types           id, name, slug, is_active, timestamps
freight_job_category      freight_job_id, category_id     (composite PK)
freight_job_truck_type    freight_job_id, truck_type_id   (composite PK)
```

The legacy columns hold **comma-separated display names**, not ids — there is no
lookup table in the old database to resolve against, so both tables must be
seeded from the distinct values in the data.

This matters more than it looks: **67 of 103 loads carry more than one truck
type.** The current single `trailer_type_required` column keeps the first and
appends the rest to the description as prose, so two-thirds of loads cannot be
filtered correctly.

`load_category` and `trailer_type_required` stay for now as denormalised
"primary" values, but the pivots become the source of truth.

### route_distances — **G3** ✅ built
See §2 above. All 663 legacy rows import and parse.

Still open: nullable `lat` / `lng` on `suburbs`, so a lane that is not in the
cache can be estimated without a third-party call. No source for those
coordinates exists in the legacy data — `suburb_master` holds id, state and name
only — so it needs an external dataset.

### freight_jobs.relisted_at — **G6** ✅ built
Legacy relisting worked by touching `date_updated`, which makes an edit and a
relist indistinguishable. A dedicated column keeps them apart and lets the board
order by "recently relisted" honestly.

## 3. Recommended Indexes

- freight_jobs: shipper_id, status, pickup_location, delivery_location, load_category
- job_quotes: job_id, carrier_id, status
- notifications: user_id, is_read
- messages: conversation_id, created_at
- users: email, role, status

## 4. Data Lifecycle Notes

- Jobs move through statuses as they progress
- Quotes expire if not accepted within a configured period
- Reviews are immutable after submission unless moderated
- Verification documents can be re-submitted after rejection
