# Legacy Data Migration

How the pre-launch FreightMove database (`old_freightmove`) is carried into the
current schema, and how to repeat the import at go-live.

## 1. Go-live procedure

The import is **re-runnable**. Every imported row stores the legacy primary key
in `legacy_id`, and each step upserts against it, so importing a fresher dump
updates the rows already present instead of duplicating them.

```bash
# 1. Restore the fresh live dump over the legacy database
mysql -u root old_freightmove < freightmove-live-YYYYMMDD.sql

# 2. Preview — writes nothing, reports counts and warnings
php artisan legacy:import --dry-run

# 3. Import
php artisan legacy:import
```

Rows created natively on the new platform have a null `legacy_id` and are never
touched by the importer.

### Options

| Option | Effect |
| --- | --- |
| `--dry-run` | Runs the whole import inside a transaction, reports, rolls back. |
| `--only=users,jobs` | Runs selected steps only: `suburbs,routes,users,jobs,quotes,blog,billing`. |

Steps run in dependency order — `suburbs` before `routes` and `jobs` (both
resolve through it), `users` before `jobs`, `jobs` before `quotes`. Restricting
with `--only` does not reorder them, but it can skip a step a later one depends
on.

The whole run is wrapped in a single transaction: if any step throws, nothing is
written.

### Connection

Configured as the `legacy` connection in `config/database.php`, driven by these
`.env` keys. Nothing in the application writes to it.

```
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_PORT=3306
LEGACY_DB_DATABASE=old_freightmove
LEGACY_DB_USERNAME=root
LEGACY_DB_PASSWORD=
```

## 2. Table mapping

| Legacy | New | Notes |
| --- | --- | --- |
| `shipper` | `users` + `user_profiles` + `carriers` | One table held both roles. |
| `load_master` | `freight_jobs` | |
| `load_quotation` | `job_quotes` | |
| `suburb_master` | `suburbs` | New table; reference data. |
| `blog_master` | `blog_posts` | |
| `subscription_master` | `subscription_plans` | New table. |
| `subscription_details` | `subscriptions` | New table. |
| `paypal_transaction` | `subscription_payments` | New table. |
| `distance_calculator` | `route_distances` | 663 cached Distance Matrix answers, plus their reuse counts. |
| `jobs`, `migrations`, `personal_access_tokens` | — | Framework tables. |
| `orders`, `email_send`, `user_subscriptions` | — | Empty in the source. |

### Route distances need parsing, not copying

`distance_calculator` stores whatever the Distance Matrix API returned, and that
changed over the life of the site: 661 rows hold display text (`3,616 km`,
`21.1 km`, `1 m`) and 2 hold the raw metre count from an earlier code path.
Durations are the same — `1 day 15 hours`, `17 hours 5 mins`, `1 min`, and two
bare second counts — pluralised inconsistently.

The importer parses both shapes into `distance_metres` / `duration_seconds` and
keeps the original text for display. Anything it cannot read is imported with
the text alone and **reported**, rather than silently zeroed. On the 06-08-2026
dump nothing was unreadable: 663/663 parsed.

### Subscription plans are matched by code, not by position

`subscription_details.subscription_type` is a code (1 monthly, 2 quarterly,
3 annual, 4 free trial), decoded from what each cohort paid. Treating it as an
index into the plan list silently swapped quarterly and annual — see
`docs/10-domain-rules.md` R8. Plans now carry a `code` column and the importer
maps to it explicitly; an unrecognised code is **reported**, not left planless.

### Decoded legacy codes

`shipper.ship_car` and `shipper.shipper_type` are unlabelled integers. They were
decoded from behaviour, not assumed:

- **`ship_car`** — accounts with `1` posted 93 of 100 loads; accounts with `2`
  submitted 126 of 127 quotes. So **1 = shipper, 2 = carrier**.
- **`shipper_type`** — type `2` accounts carry company names and ABNs at roughly
  ten times the rate of type `1`. So **1 = individual, 2 = business**.

## 3. Decisions that need review

### How existing customers move across

freightmove.au is live with active customers. Nobody's email or password
changes, and **nothing about the migration blocks a sign-in**. The sequence:

1. **They sign in with the password they already have.** The imported bcrypt
   hash is byte-identical to the old site's — verified across all 536 accounts.
2. **The hash is silently upgraded** from bcrypt cost 10 to cost 12 during that
   login, the only moment the plaintext is available. Invisible to them, and a
   failure here can never block a valid sign-in.
3. **A banner invites them to choose a new password.** Not a gate — these are
   paying customers mid-task, and their current password still works. Dismissing
   it hides it for the session; it returns next sign-in.
4. **Choosing a new one** applies the current policy and revokes every other
   session, keeping the one in use so they are not signed out mid-flow.

The invitation is driven by `users.password_changed_at`. Null means "never
chosen on this platform" — the state every imported account starts in.
Registration, password reset and this change flow all set it.

The old password policy was looser than the current one, so many existing
passwords would fail today's rules. That is fine and deliberate: **the policy
governs passwords being set, never one being checked.** A test
(`test_a_weak_legacy_password_still_signs_in`) pins this down, because getting
it wrong would lock every customer out of their own account.

There is a security reason to invite the change beyond housekeeping: the old
site carried a master-password bypass that could open any account
(`docs/11-security.md` §5), so no legacy credential can be assumed private.

### Passwords carry over

Legacy hashes are bcrypt (`$2y$10$`, 60 chars) — the format Laravel uses. All
536 accounts keep their existing password; no reset email is needed.

They are written through the query builder rather than Eloquent **on purpose**.
The `password` attribute on `User` has a `hashed` cast which calls
`Hash::verifyConfiguration()` and throws when a hash's cost does not match the
app's. Legacy hashes are cost 10; `BCRYPT_ROUNDS` is 12. Assigning them through
a model would abort the import. Laravel rehashes each password transparently on
next successful login.

### Weight units were never recorded

The legacy weight field was free text holding a mix of kilograms and tonnes with
no unit stored. Values range from `1.30` to `34000`.

Road freight cannot exceed roughly 50 tonnes, so the importer treats anything
**above 100 as kilograms** and anything **at or below 100 as tonnes**. This is
an inference, not recovered data. The untouched original is preserved in the job
description as `Weight as entered: …`, so every conversion is auditable.

Of 73 loads with numeric weights: 11 are at or below 100, 62 above.

### Rows that cannot be imported

These are gaps in the source data, not mapping failures. The legacy schema had
**no foreign key constraints**, so deleted parents left orphaned children.

| Skipped | Count | Reason |
| --- | --- | --- |
| Loads | 3 of 103 | The shipper account no longer exists. |
| Quotes | 139 of 143 | The load quoted on no longer exists. |
| Subscription periods | 12 of 90 | The account no longer exists. |

**The quote loss is severe — only 4 of 143 survive.** `load_master` holds 103
rows while quotes reference 139 distinct loads that are gone, so loads appear to
have been purged over time while their quotes remained. Nothing can reconstruct
them; the quotes reference ids that exist nowhere in the dump. If quote history
matters, check whether an older backup still holds those loads before go-live.

### Detail with no column in the new schema

`load_master` captured length, width, height, quantity and a multi-select of
categories and trailer types. The new `freight_jobs` has single-value
`load_category` and `trailer_type_required` columns.

The first value of each list goes into its column for filtering; the complete
lists, dimensions and quantity are appended to the job description so nothing
captured is silently dropped.

### Other mappings worth knowing

- `readyon` used `1970-01-01` as "no date"; imported as null.
- `load_quotation` recorded no accept/decline state — every quote imports as
  `pending`.
- Legacy suburb ids resolve to `"Suburb, STATE"` strings. All 103 loads resolved
  successfully.
- Carrier rows are created for every `ship_car = 2` account, but fleet size,
  service radius and insurance stay null — the legacy schema never captured
  them. Carriers complete these in the new dashboard.
- Blog posts import as `published` with a null `author_id`; the legacy
  `created_by` held a name string, not a user reference.

## 4. Category taxonomy mismatch

The categories in live data do not match the twelve advertised on the new
homepage.

**Live data:** Machinery (Mobile) 26, General Part Load 12, Trucks or Prime
Movers 11, Pallets (Less Than a Load) 9, Car 9, Shipping Containers 6, Machinery
(Stationary) 5, Bulk Products, General Full Load, Trailers to be Towed/carried,
Caravan or Camper Trailer, Boat.

**Homepage:** Heavy Haulage, General Freight, Container Transport, Machinery
Transport, Livestock, Boat, Truck & Trailer, Grain & Hay, Bulk Tipper, Liquid
Tanker, Portable Building, Palletised Freight.

Imported jobs keep their original category strings, so the two vocabularies
currently coexist. They should be reconciled before launch — ideally by letting
the categories customers actually select drive the site.

Trailer types in live data: Drop Deck 63, Low Loader 40, Flat Top 38, Float 38,
Tiltray 21, Rigid Flat Top, Tautliner, Crane Truck, Car Carrier, Platform,
Bobtail Prime Mover, Side Loader, Tipper, Rigid Panteck, UTE, Tanker, Livestock.
