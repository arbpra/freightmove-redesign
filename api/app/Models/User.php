<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'role',
        'status',
        'avatar_url',
        'timezone',
        'locale',
        'password_changed_at',
    ];

    /**
     * Honorifics and post-nominals, which are not names.
     *
     * Faker generates them and real sign-up forms collect them, so a naive
     * split on the first space yields a first name of "Mr." and a surname of
     * "Camron Kling III".
     */
    private const TITLES = ['mr', 'mrs', 'ms', 'miss', 'mx', 'dr', 'prof', 'sir', 'madam', 'rev'];

    private const SUFFIXES = ['jr', 'sr', 'i', 'ii', 'iii', 'iv', 'v', 'phd', 'md', 'dvm', 'dds', 'esq'];

    /**
     * Splits a display name into a given name and a surname.
     *
     * One implementation, used by the backfill migration and by
     * `legacy:import`, because the two have to agree — a user whose name was
     * split one way on migration and another way on re-import would see their
     * own name change under them.
     *
     * A heuristic, and honest about it: "Mary Jane Van Der Berg" cannot be
     * resolved without asking her. Where the original first/last columns still
     * exist, use those instead of this.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function splitName(?string $name): array
    {
        $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $normalise = static fn (string $w) => mb_strtolower(rtrim($w, '.,'));

        // Drop a leading title, but never the only word left: "Dr" alone is
        // the best name on record and is better than nothing.
        while (count($words) > 1 && in_array($normalise($words[0]), self::TITLES, true)) {
            array_shift($words);
        }

        while (count($words) > 1 && in_array($normalise(end($words)), self::SUFFIXES, true)) {
            array_pop($words);
        }

        if ($words === []) {
            return ['', null];
        }

        $first = array_shift($words);

        return [$first, $words === [] ? null : implode(' ', $words)];
    }

    /**
     * Sets the name parts and rebuilds the display name from them.
     *
     * `name` stays a real column because Laravel, the mailer and every view
     * read it, but it is derived: this is the only place the parts and the
     * whole are allowed to meet, so they cannot drift. Passing a blank surname
     * is legitimate — not everyone has one recorded.
     */
    public function setNameParts(?string $first, ?string $last): void
    {
        $first = trim((string) $first);
        $last = trim((string) $last);

        $this->first_name = $first !== '' ? $first : null;
        $this->last_name = $last !== '' ? $last : null;

        $whole = trim($first.' '.$last);

        // Never blank the display name. An account with no parts recorded keeps
        // whatever it already had rather than becoming nameless.
        if ($whole !== '') {
            $this->name = $whole;
        }
    }

    /** The given name, falling back to the first word of the display name. */
    public function firstName(): string
    {
        return $this->first_name ?: trim(explode(' ', trim((string) $this->name))[0] ?? '');
    }

    /** The surname, or an empty string when none was recorded. */
    public function lastName(): string
    {
        if ($this->last_name) {
            return $this->last_name;
        }

        $name = trim((string) $this->name);
        $space = mb_strpos($name, ' ');

        return $space === false ? '' : trim(mb_substr($name, $space + 1));
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function carrier(): HasOne
    {
        return $this->hasOne(Carrier::class);
    }

    /** Jobs this user posted as a shipper. */
    public function freightJobs(): HasMany
    {
        return $this->hasMany(FreightJob::class, 'shipper_id');
    }

    /** Quotes this user submitted as a carrier. */
    public function quotes(): HasMany
    {
        return $this->hasMany(JobQuote::class, 'carrier_id');
    }

    public function reviewsReceived(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewed_user_id');
    }

    public function reviewsWritten(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function verificationDocuments(): HasMany
    {
        return $this->hasMany(VerificationDocument::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isShipper(): bool
    {
        return $this->role === UserRole::Shipper;
    }

    public function isCarrier(): bool
    {
        return $this->role === UserRole::Carrier;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * A subscription that has not lapsed.
     *
     * `ends_on` null means open-ended, so it counts as current. The status
     * column alone is not trusted: legacy periods were imported with a status
     * derived from their end date, and nothing keeps it fresh as time passes.
     */
    public function hasActiveSubscription(): bool
    {
        // Delegates to Subscription::scopeCurrent so there is one definition of
        // "entitled". This used to read `status != 'cancelled'`, which quietly
        // counted a **pending** period — so a carrier could hold the paid
        // product indefinitely by reserving a plan and never paying for it.
        return $this->subscriptions()->current()->exists();
    }

    /**
     * Whether this carrier may submit quotes.
     *
     * Enforcement is off by default — see config/freightmove.php for why, and
     * for the legacy grace period this honours.
     */
    public function canQuote(): bool
    {
        return $this->meetsVerificationGate() && $this->meetsSubscriptionGate();
    }

    /**
     * The verification gate, off by default.
     *
     * The marketing site promises verified carriers, so this should end up on.
     * Today it would empty the marketplace: the previous platform had no
     * verification at all, so **none** of the 291 migrated carriers is verified.
     */
    public function meetsVerificationGate(): bool
    {
        if (! config('freightmove.verification.require_to_quote')) {
            return true;
        }

        return $this->profile?->verification_status === VerificationStatus::Verified;
    }

    private function meetsSubscriptionGate(): bool
    {
        if (! config('freightmove.quoting.require_subscription')) {
            return true;
        }

        if ($this->hasActiveSubscription()) {
            return true;
        }

        $graceUntil = config('freightmove.quoting.grandfather_legacy_until');

        return $this->legacy_id !== null
            && $graceUntil !== null
            && today()->lte(\Illuminate\Support\Carbon::parse($graceUntil));
    }

    /**
     * True when this account came from the pre-launch site and its owner has
     * not yet chosen a password on this platform.
     *
     * Their existing password still works — nothing is blocked. This only
     * drives the invitation to set a new one after signing in, which matters
     * because the old site carried a master-password bypass that could have
     * exposed any account (docs/11-security.md section 5).
     */
    public function shouldUpdatePassword(): bool
    {
        return $this->legacy_id !== null && $this->password_changed_at === null;
    }
}
