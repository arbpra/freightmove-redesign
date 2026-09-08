<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Front end
    |--------------------------------------------------------------------------
    |
    | Where the Angular app is served. Links that a person is meant to click —
    | password reset, and email verification later — must point here, not at the
    | API, which has no pages of its own.
    |
    | Shares the FRONTEND_URL key with config/cors.php on purpose: one origin,
    | one setting, so CORS and emailed links can never disagree.
    |
    */

    /*
     * The **first** entry in FRONTEND_URL.
     *
     * That variable is a comma-separated list for CORS, because the API on its
     * own subdomain legitimately serves more than one front end. Links people
     * click can only point at one of them, so the first is treated as canonical.
     * Put the site you want password-reset emails and PayPal returns to land on
     * at the front of the list.
     */
    'frontend_url' => trim(explode(',', (string) env('FRONTEND_URL', 'http://localhost:4200'))[0]),

    /*
    |--------------------------------------------------------------------------
    | Carrier load board
    |--------------------------------------------------------------------------
    */

    'board' => [
        /*
         * How far back the board reaches, in days.
         *
         * The legacy site hardcoded 7 days (docs/10-domain-rules.md R4). Kept as
         * a setting because the right number depends on volume: too short and a
         * quiet week looks like an empty marketplace, too long and carriers wade
         * through loads that have already moved.
         *
         * Set to 0 to disable the window entirely and show every open load.
         */
        'recency_days' => (int) env('FM_BOARD_RECENCY_DAYS', 7),

        /*
         * Minimum hours between relists of the same load.
         *
         * The legacy site had no bump action at all — a shipper resurfaced a
         * load by editing it, which touched `date_updated` (R5). That has no
         * limit, so a shipper could sit at the top of the board indefinitely at
         * everyone else's expense. The explicit action gets an explicit floor.
         *
         * Set to 0 to allow relisting at will.
         */
        'relist_cooldown_hours' => (int) env('FM_RELIST_COOLDOWN_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quoting
    |--------------------------------------------------------------------------
    */

    'quoting' => [
        /*
         * Whether an active subscription is required to submit a quote.
         *
         * The subscription IS the paid product (docs/10-domain-rules.md R3), so
         * this should end up true. It defaults to FALSE because of what the
         * migrated data actually looks like:
         *
         *   291 carriers imported
         *     2 hold a subscription that has not expired
         *
         * Switching this on before those carriers are re-subscribed would lock
         * 289 of them out of the marketplace on day one. Turn it on once the
         * subscription flow is live and carriers have had a chance to renew.
         */
        'require_subscription' => (bool) env('FM_REQUIRE_SUBSCRIPTION_TO_QUOTE', false),

        /*
         * Grace period for accounts carried over from the previous site.
         *
         * While enforcement is on, carriers with a `legacy_id` may still quote
         * until this date, so the cut-over does not strand paying customers
         * mid-migration. Null disables grandfathering.
         *
         * Format: Y-m-d.
         */
        'grandfather_legacy_until' => env('FM_LEGACY_QUOTING_GRACE_UNTIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shipper contact details
    |--------------------------------------------------------------------------
    */

    'shipper_contacts' => [
        /*
         * Whether an active subscription is required to see who posted a load
         * — the shipper's name, phone and email.
         *
         * This is the disintermediation guard, and when it is on it is the
         * clearest answer to "what does the subscription actually buy?".
         * Quoting is free (see `quoting.require_subscription` above), so the
         * contact details are the product.
         *
         * It defaults to FALSE for the same reason quoting does. Of the 291
         * migrated carriers, 2 hold a subscription that has not expired.
         * Switching this on before those carriers have been given a reason and
         * a chance to subscribe turns a working marketplace into a paywall on
         * day one, against an audience that has never been asked to pay for
         * this platform.
         *
         * Turning it on is a one-line env change and needs no deploy — the
         * `subscribe` lock state, its copy, and the "See plans" call to action
         * are all already built and tested. `PublicLoadDetailResource` reports
         * the flag to the client as `shipper_requires_subscription`, so the
         * page tells the truth in both modes without a rebuild.
         *
         * Two things to have in place before flipping it:
         *
         *   1. Carriers warned. Removing access people already have generates
         *      support load and churn in a way that never granting it does not.
         *   2. The board's own messaging checked — `requires_subscription`
         *      there is the QUOTING flag, and the two are independent.
         */
        'require_subscription' => (bool) env('FM_REQUIRE_SUBSCRIPTION_FOR_CONTACTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Carrier subscriptions
    |--------------------------------------------------------------------------
    |
    | The plans themselves live in `subscription_plans`, seeded by
    | SubscriptionPlanSeeder from what freightmove.au/carriers-subscription
    | advertises. Only the rules around them are configured here.
    |
    */

    'subscriptions' => [
        /*
         * Last date on which a carrier may *start* the free trial.
         *
         * The live pricing page says "Offer ends 31-03-2026" — a date that has
         * now passed, while the legacy database shows trials still being handed
         * out in July 2026. Rather than pick a side, this defaults to null,
         * which means the offer is open: the current behaviour is preserved and
         * nobody is locked out by a change nobody asked for.
         *
         * Set a Y-m-d date to actually close it, and update the page to match.
         */
        'trial_offer_ends' => env('FM_TRIAL_OFFER_ENDS'),

        /*
         * Subscription lifecycle reminders, sent by `subscriptions:remind`.
         *
         * The previous site sent two — one three days before the end date, one
         * after — and this widens both into a cadence.
         *
         * These are milestones, not exact match days. A carrier gets at most
         * one email per milestone ever, and the copy quotes the real elapsed
         * time, so a sweep that runs late still says something true instead of
         * saying nothing at all.
         */
        'reminders' => [
            // Days BEFORE the end date.
            'lead_days' => (string) env('FM_SUBSCRIPTION_REMINDER_DAYS', '5,3,1'),

            // Days AFTER it, before the monthly cadence takes over.
            'after_days' => (string) env('FM_SUBSCRIPTION_REMINDER_AFTER_DAYS', '3,7,15'),

            /*
             * Then one a month, on the anniversary of the end date, for this
             * many months. 0 means never stop.
             *
             * Open-ended is what was asked for and is the default. Be aware of
             * what it means on this data: 88 of the 90 migrated subscription
             * periods are already expired, some since 2024, so "forever"
             * includes carriers who left two years ago. Mailing people who
             * have stopped engaging is what generates spam complaints, and
             * complaints are scored against the sending domain — which is the
             * same domain the quote and password-reset emails go out on. If
             * reminders start landing in spam, so do those.
             */
            'monthly_months' => (int) env('FM_SUBSCRIPTION_REMINDER_MONTHS', 0),

            /*
             * Never remind about a period that ended before this date (Y-m-d).
             *
             * Blank means no cutoff. Set it to roughly today's date before the
             * first live run and the historical lapses above are left alone
             * while everything from now on is reminded normally. See
             * docs/12-deployment-siteground.md.
             */
            'ignore_expiries_before' => env('FM_SUBSCRIPTION_REMINDER_IGNORE_BEFORE'),

            /*
             * How many milestones a single sweep may email one subscription.
             *
             * The first sweep over a long-lapsed subscription finds every past
             * milestone due at once. Only the newest is sent; the rest are
             * recorded as skipped so they cannot fire later. Without this, one
             * carrier would receive two years of reminders in one delivery.
             */
            'max_per_sweep' => 1,
        ],

        /*
         * How a carrier pays.
         *
         *   manual — the carrier is given payment instructions and an admin
         *            confirms the money arrived. Works with no credentials.
         *   paypal — not implemented. The previous site used PayPal, and the
         *            transaction history imported with it, but wiring a live
         *            gateway needs credentials and a merchant decision.
         *
         * `PaymentGateway` is the seam either one plugs into.
         */
        'gateway' => env('FM_PAYMENT_GATEWAY', 'manual'),

        /*
         * Shown to a carrier who chooses a paid plan under the manual gateway.
         * Deliberately blank by default: invented bank details are worse than
         * none, and this is the one place where a placeholder could cost
         * somebody real money.
         */
        'payment_instructions' => env('FM_PAYMENT_INSTRUCTIONS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Places
    |--------------------------------------------------------------------------
    |
    | Key for the address autocomplete on the post-a-load form, served to the
    | browser by GET /api/v1/public/config.
    |
    | It lives here rather than in the Angular bundle because that bundle is
    | committed — SiteGround has no Node runtime, so `deploy/web` is in the
    | repository, and a key compiled into it would be in git. Here it sits in
    | `.env`, which is not.
    |
    | It is public once served, which is unavoidable: a Maps key has to reach
    | the browser. Restrict it instead — HTTP referrers limited to this site,
    | Places API only, and a budget alert. See docs/11-security.md section 5a.
    |
    | Blank is supported: the address fields become plain text inputs.
    |
    */

    'google_maps_key' => env('FM_GOOGLE_MAPS_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Load photos
    |--------------------------------------------------------------------------
    |
    | Pictures a shipper attaches to a load. The legacy site stored these in
    | `public/images/load` and served them straight from the document root.
    |
    */

    'loads' => [
        /*
         * How many photos one load may carry.
         *
         * The legacy schema held a single `image` column. More than one is a
         * genuine improvement — a machine looks different from three angles —
         * but this is a load board, not an album.
         */
        'max_images' => (int) env('FM_LOAD_MAX_IMAGES', 6),

        'max_image_kb' => (int) env('FM_LOAD_MAX_IMAGE_KB', 6144),

        /*
         * Emailing every carrier when a load is posted.
         *
         * The previous site intended this — `load_master.bulk_email` and the
         * `email_send` table exist for it — but never delivered one. The
         * fan-out loop in `bulk-email.blade.php` is commented out, and the
         * 7,888 QuotationMail jobs it queued instead still sit unprocessed
         * with `attempts = 0`. So no carrier has ever received one of these.
         *
         * OFF by default, and that is not caution for its own sake. There are
         * 295 active carriers and roughly 40 loads a month, which is ~11,800
         * messages a month to an audience that has never had any. Switching it
         * on is a decision about sending reputation, not a config tidy-up: the
         * domain that carries these also carries password resets and quote
         * notifications, and complaints are scored against all of it together.
         *
         * Turn it on deliberately, after `loads:alert --dry-run`.
         */
        'alerts' => [
            'enabled' => (bool) env('FM_LOAD_ALERTS', false),

            /*
             * Who hears about a new load.
             *
             *   all         every active carrier — what was asked for, 295 today
             *   subscribed  only carriers with a current subscription — 6 today
             *   verified    only verified carriers — 0 today, nobody is verified
             *
             * `subscribed` makes the alert part of what the subscription buys,
             * which is the argument for the paid product. `all` is the wider
             * net and the louder one.
             */
            'audience' => env('FM_LOAD_ALERT_AUDIENCE', 'all'),

            /*
             * Send the carrier alert to ONE address instead of the audience.
             *
             * Set on staging, blank on live. With an address here the fan-out
             * is replaced by a single message — the same email a carrier would
             * receive, addressed to you — so the content and the links can be
             * checked without 295 people finding out.
             *
             * This is what the previous site did, except it did it by
             * commenting the carrier loop out and hard-coding an address in
             * the middle of a Blade view. That is why nobody noticed for two
             * years: the code looked like it was sending. As a setting it is
             * visible, it is reported by `loads:alert`, and clearing one line
             * of .env is the whole difference between staging and live.
             *
             * Test sends are deliberately not written to the `load_alerts`
             * ledger: those rows mean "this carrier was told", and no carrier
             * was.
             */
            'test_recipient' => env('FM_LOAD_ALERT_TEST_RECIPIENT'),

            /*
             * Where the operator's copy goes. One message per load, not per
             * carrier. Falls back to the enquiry address.
             */
            'admin_recipient' => env('FM_LOAD_ALERT_ADMIN', env('FM_CONTACT_RECIPIENT', env('MAIL_FROM_ADDRESS'))),

            /*
             * How many carriers one queued batch handles. Small enough that a
             * failure re-runs a little work rather than a lot, and that a
             * provider rate limit throttles rather than rejects.
             */
            'batch_size' => (int) env('FM_LOAD_ALERT_BATCH', 50),
        ],

        /*
         * Accepted types, checked against file **contents** via finfo, not
         * against the extension.
         *
         * SVG is deliberately absent. It is an XML document that can carry
         * script, and unlike verification documents — which are private and
         * only ever sent back as attachments — these are displayed inline on a
         * public board. An SVG here would be stored XSS running on our origin
         * in another user's browser. Freight photos are never SVG in practice,
         * so nothing real is lost.
         */
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Carrier verification
    |--------------------------------------------------------------------------
    */

    'verification' => [
        /*
         * Documents a carrier is asked for, keyed by the value stored in
         * `verification_documents.document_type`.
         *
         * `required` marks the ones that must be approved before a carrier can
         * be verified. Insurance is required because it is the claim the
         * marketplace makes on a carrier's behalf; a driver licence is useful
         * for a sole trader but meaningless for a fleet, so it is optional.
         */
        'document_types' => [
            'abn' => ['label' => 'ABN registration', 'required' => true],
            'insurance' => ['label' => 'Certificate of currency (insurance)', 'required' => true],
            'licence' => ['label' => 'Driver licence', 'required' => false],
            'other' => ['label' => 'Something else', 'required' => false],
        ],

        /*
         * Upload limits. Kept deliberately tight: these are scans of one or two
         * pages, not photo albums.
         */
        'max_upload_kb' => (int) env('FM_MAX_UPLOAD_KB', 8192),

        'allowed_mime_types' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],

        /*
         * Whether a carrier must be verified before quoting.
         *
         * The marketing site says "verified carriers only", so this should end
         * up true. It defaults to FALSE for the same reason the subscription
         * gate does, only more starkly:
         *
         *   291 carriers imported
         *     0 verified — the previous platform had no verification at all
         *
         * Turning this on before carriers have submitted documents would empty
         * the marketplace completely. Turn it on once the queue has been worked
         * through, and consider `grandfather_legacy_until` alongside it.
         */
        'require_to_quote' => (bool) env('FM_REQUIRE_VERIFICATION_TO_QUOTE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact form
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Scheduled work over HTTP
    |--------------------------------------------------------------------------
    |
    | `crontab` is not available over SiteGround's SSH, so the scheduled sweeps
    | can also be triggered by fetching a URL. The token is what separates that
    | from the previous site's `/reminder-one-email`, which was a plain public
    | route anyone could hit to fire mail at the whole user base.
    |
    | Generate one with:  php artisan cron:token
    |
    | Blank means the cron routes refuse everything. That is deliberate — an
    | endpoint that mails hundreds of people must fail closed.
    |
    */
    'cron' => [
        'token' => env('FM_CRON_TOKEN'),
    ],

    'contact' => [
        /*
         * Where website enquiries are emailed.
         *
         * Falls back to the application's own from-address so a fresh install
         * does not silently drop enquiries. Every enquiry is stored in
         * `contact_messages` regardless, so nothing is lost if this is wrong.
         */
        'recipient' => env('FM_CONTACT_RECIPIENT', env('MAIL_FROM_ADDRESS')),

        /*
         * Where a "payment received" copy is sent when a carrier pays.
         *
         * Comma-separated for more than one person. Falls back to the enquiry
         * address, because whoever reads enquiries is the person who will be
         * asked about a payment.
         *
         * This is the only notice that money arrived. Under the PayPal gateway
         * nobody touches the transaction — the carrier pays, the capture
         * confirms and the subscription switches itself on — so without it the
         * first anyone here knows of a sale is the bank statement.
         */
        'payment_recipient' => env('FM_PAYMENT_RECIPIENT', env('FM_CONTACT_RECIPIENT', env('MAIL_FROM_ADDRESS'))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transactional email
    |--------------------------------------------------------------------------
    */

    'mail' => [
        /*
         * Which in-app notification types are also emailed.
         *
         * A product decision, not a technical one. Emailing every event trains
         * people to filter the sender, and then the one message that mattered
         * goes unread too. These are the events where the recipient is waiting
         * on an answer and may not be signed in:
         *
         *   quote.received  a carrier priced your load
         *   quote.accepted  you won the job
         *   message.received  someone is asking you a question
         *   document.*      a verification decision they cannot act on unseen
         *   carrier.verified  the badge they have been waiting for
         *
         * Deliberately absent: quote.declined and quote.withdrawn (nothing to
         * act on), job.completed and review.received (the bell is enough).
         */
        'notify' => [
            'quote.received',
            'quote.accepted',
            'message.received',
            'document.approved',
            'document.rejected',
            'carrier.verified',
        ],

        /*
         * Send email through the queue rather than during the request.
         *
         * An SMTP handshake costs a second or more, and "post a load" should
         * not wait on it. Defaults to FALSE so the application works with no
         * worker configured — turning it on without a running queue worker
         * means mail is written to the queue and never sent, which is worse
         * than being slow.
         *
         * To enable on shared hosting: set this true and add a cron running
         *   php artisan queue:work --stop-when-empty --max-time=55
         * every minute. See docs/12-deployment-siteground.md.
         */
        'queue' => (bool) env('FM_MAIL_QUEUE', false),
    ],

];
