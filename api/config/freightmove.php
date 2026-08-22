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

    'contact' => [
        /*
         * Where website enquiries are emailed.
         *
         * Falls back to the application's own from-address so a fresh install
         * does not silently drop enquiries. Every enquiry is stored in
         * `contact_messages` regardless, so nothing is lost if this is wrong.
         */
        'recipient' => env('FM_CONTACT_RECIPIENT', env('MAIL_FROM_ADDRESS')),
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
