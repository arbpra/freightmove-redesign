# Development Roadmap

Status as at 10 August 2026. Ticked items are built and verified; everything
else is outstanding.

## Phase 1 — Discovery and UX Definition ✅
- [x] Finalize SRS and success metrics
- [x] Approve information architecture and user journeys
- [x] Finalize design system, component inventory, and page flows

## Phase 2 — Foundation Setup ✅
- [x] Initialize Angular 20 application with standalone architecture
- [x] Set up Laravel 12 API skeleton and environment structure
- [x] Configure authentication, middleware, and role scaffolding
- [x] Create database migrations and seeders

## Phase 3 — Core Marketplace MVP ✅

The job and quote lifecycle is now complete end to end: a shipper posts a
load, carriers on that lane quote, the shipper compares and books one, and
the losing quotes are declined automatically.
- [x] Build public marketing pages — home and contact
- [x] Implement user registration and login
- [x] Build shipper job creation and listing
- [x] Build carrier load board and quote submission
- [x] Build carrier profile and verification flow
- [x] Implement quote acceptance

### Legacy parity work (blocks cutover)

Identified by reconciling `FREIGHTMOVE_LEGACY_ANALYSIS_AND_V2_SPEC.md` with the
live data. Full detail in `docs/10-domain-rules.md`; ordered by data affected.

- [x] **G2 — multi-select categories and truck types.** Lookup tables seeded
      from live data plus two pivots. Re-import reconciles exactly: 351/351
      truck-type values, 114/114 categories.
- [x] **G1 — `availability`.** Enum column plus legacy code mapping; all 100
      imported loads now carry their urgency signal.
- [x] **G4 — subscription gate built** (`JobQuotePolicy`), tested both ways,
      and the subscription product itself is now built: four plans, free
      trial, checkout, admin payment confirmation, cancellation.
      **Enforcement defaults to OFF** — only 2 of 291 migrated carriers hold a
      current subscription, so switching it on today locks out 289. Your call,
      via `FM_REQUIRE_SUBSCRIPTION_TO_QUOTE`; a legacy grace period is available.
- [x] **G3 — route distance cache.** `route_distances` table plus a `routes`
      import step; all 663 legacy rows import, and every one parses into both
      canonical units. 320 recorded cache hits carried across. Served by
      `GET /public/routes/{pickup}/{dropoff}`, with `GET /public/suburbs` to
      resolve the ids.
- [x] **G5 — recency window**, `FM_BOARD_RECENCY_DAYS` (default 7, 0 disables).
- [x] **G6 — relist.** `POST /shipper/jobs/{job}/relist`, plus a Bump action on
      the shipper's load list. Editing a load deliberately does *not* bump it.
      Cooldown `FM_RELIST_COOLDOWN_HOURS`, default 24 — legacy had no limit,
      which let one shipper hold the top of the board indefinitely.
- [x] Re-ran `legacy:import` after G1/G2 — backfilled and reconciled.
- [x] Re-ran again for G3 — 663/663, and a second run confirmed it is still
      idempotent.

**Phase 3 is complete.** Carrier verification was the last item: profile
editing, document upload, an admin review queue, and a derived verification
status. Enforcement is **off by default** — the previous platform had no
verification at all, so **none** of the 291 migrated carriers is verified and
switching `FM_REQUIRE_VERIFICATION_TO_QUOTE` on today would empty the
marketplace entirely. That is the third such flag, alongside the subscription
gate; all three are decisions waiting on you rather than missing code.

**All legacy parity gaps are now closed.** What remains before cutover is
decisions rather than code: see "Known gaps blocking launch" below.

Also delivered in this phase, outside the original plan:
- [x] Carrier subscriptions — the paid product. Public pricing page at
      `/carriers-subscription`, carrier self-service, admin payment queue.
      **Taking card payments still needs a gateway decision** — see below.
- [x] Public suburb autocomplete and cached route distances
- [x] Contact form backend — stored then emailed, so a mail outage cannot
      lose an enquiry
- [x] Password reset end to end: `/forgot-password` and
      `/reset-password/:token` pages, plus the fix for the emailed link,
      which pointed at a web route this API-only app does not have and threw
      for every registered address
- [x] Design system: tokens, two-family typography, motion primitives
- [x] Legacy data migration (`php artisan legacy:import`) — see doc 09
- [x] Reusable SEO service: title, meta, canonical, Open Graph, JSON-LD

## Phase 4 — Experience Layer ✅
- [x] Build dashboard shells for shipper, carrier, and admin — navigation rail
      on desktop, tab bar on mobile, one role-aware link set feeding both
      (`layout/dashboard-nav.ts`), rebuilt on the brand tokens
- [x] Implement notifications and messaging foundation — notifications
      (events, API, topbar bell) and messaging (thread per load between the
      shipper and a carrier who has quoted, two-pane centre, unread counts)
- [x] Create responsive layouts and premium UI components — every screen
      built since the design system landed is on the brand tokens and
      responsive from 320px; the scaffold's separate palette is gone
- [x] Add analytics and admin views — overview with marketplace rates and
      cut-over progress, account oversight with suspend/reinstate, load
      oversight, verification queue. Support tickets and payments have
      tables but no panel yet.

## Phase 5 — Optimization and Growth 🚧
- [ ] Add smart matching logic and notification rules
- [~] Integrate files, documents, and review flows — **documents** (carrier
      verification uploads) and **reviews** are done; the job lifecycle now
      closes properly, and ratings are derived from reviews rather than
      invented. File attachments on messages are not built.
- [~] Add performance tuning and SEO improvements — twelve freight category
      landing pages with per-category copy, JSON-LD (Service, FAQPage,
      BreadcrumbList), generated sitemap/robots, and 301s from the previous
      site's category URLs. Performance tuning itself is not started.
- [ ] Prepare AI service hooks for future modules

## Phase 6 — Launch and Iterate
- [ ] QA, security review, and deployment readiness
- [ ] Production deployment on hosting infrastructure
- [ ] Monitor performance, conversion, and support issues
- [ ] Plan future enhancements and AI features

## Known gaps blocking launch

Carried here so they are not lost between documents.

| Gap | Detail |
| --- | --- |
| PayPal credentials not supplied | The PayPal gateway is **built and tested** (Orders v2, capture verification, signature-verified webhooks). It is inactive until `PAYPAL_CLIENT_ID` / `PAYPAL_CLIENT_SECRET` / `PAYPAL_WEBHOOK_ID` are set and `FM_PAYMENT_GATEWAY=paypal`. Until then the manual gateway takes payments offline. |
| Free trial offer date | The pricing page says "Offer ends 31-03-2026", which has passed, while the legacy data shows trials granted in July 2026. The trial defaults to **open**; set `FM_TRIAL_OFFER_ENDS` to close it, and update the page either way. |
| Placeholder contact details | `1300 123 456` and `info@freightmove.au` are template values, in the header, footer, contact page and JSON-LD. The live site uses `pkaystp@bigpond.com`. |
| Unverifiable claims in copy | "Reply within one business hour", "Mon–Fri 7am–7pm AEST", "seven days a week", and the stats strip figures were written as plausible placeholders, not supplied facts. |
| `/worldwide-transport` has no home | The old site had this category page; none of the twelve is an equivalent. Not redirected, because pointing it at unrelated freight is a soft 404. Build it or retire it. |
| Category taxonomy mismatch | The homepage advertises twelve freight types that do not match what customers actually select in live data. Seeding the lookup tables in G2 forces this decision. See doc 09 §4. |
| Legacy production backdoor | The old site's shipper login accepts a hardcoded master password that opens **any** account. Not reproduced in V2, but live on the current site until it is retired. See doc 10 §7. |
| Terms and Privacy pages | Linked from registration and the footer; both point at `#faq`. |

## Recommended Delivery Order
1. ~~Public marketing site~~ ✅
2. ~~Authentication and role-based routing~~ ✅
3. ~~Job and quote lifecycle~~ ✅ — post, board, quote, compare, book, relist
4. Messaging and notifications
5. Admin panel and analytics
6. AI readiness enhancements
