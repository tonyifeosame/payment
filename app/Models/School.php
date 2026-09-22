<?php

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class School extends Model implements CanResetPassword
{
    use CanResetPasswordTrait;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'admin_password',
        'account_number',
        'account_name',
        'bank',
        'bank_code',
        'paystack_recipient_code',
        'address',
        'receipt_footer',
        'current_academic_term_id',
    ];

    /**
     * Never serialised: the password hash and the Paystack recipient handle are
     * internal, and the payment page passes the school to JSON-producing views.
     */
    protected $hidden = [
        'admin_password',
        'paystack_recipient_code',
        'account_number',
        'bank_code',
    ];

    public function getRouteKeyName()
    {
        return 'slug';
    }

    /**
     * URL segments routes/web.php owns under /admin/, which a school slug may
     * therefore never be (L10).
     *
     * The slug is this model's route key, so `/admin/{slug}/…` competes for the
     * same space as the literal admin routes. Only ONE of them actually shadows
     * a school today: `admin/reset-password/{token}` is registered before the
     * `admin/{school:slug}` prefix and its wildcard swallows the segment after
     * it, so every page of a school slugged `reset-password` resolves to the
     * password-reset form instead. `login`, `logout`, `forgot-password` and
     * `manifest` are fixed two-segment paths and do not collide — they are
     * reserved anyway, because what made reset-password dangerous was a wildcard
     * child being added to a segment that had looked safe.
     *
     * Reserving a segment costs a school nothing: it only applies when the whole
     * name slugifies to exactly that word, and the existing -1/-2 loop gives it
     * the next free slug.
     */
    public const RESERVED_SLUGS = [
        'admin',
        'login',
        'logout',
        'forgot-password',
        'reset-password',
        'manifest',
    ];

    /**
     * The slug a school registering under this name should get.
     *
     * The one place a slug is minted. Keeps the long-standing behaviour — append
     * -1, -2, … until the slug is free — and treats a reserved segment as though
     * it were already taken, so "Reset Password" becomes `reset-password-1`.
     */
    public static function availableSlugFor(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (self::slugIsUnavailable($slug)) {
            $slug = $base.'-'.($i++);
        }

        return $slug;
    }

    /** Taken by another school, or owned by the router. */
    public static function slugIsUnavailable(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true)
            || self::where('slug', $slug)->exists();
    }

    // -----------------------------------------------------------------------
    // Identity lookups (H4). The login identifier is the school NAME and the
    // password-reset identifier is the school EMAIL, both compared
    // case-insensitively. Each is unique that way for every school created since
    // the case-insensitive unique indexes were added; for historical rows the
    // migration refuses to run over duplicates, so ambiguity should not exist —
    // but these lookups still fail closed: two matches is treated as no match,
    // never as "the first one".
    // -----------------------------------------------------------------------

    /** The one school with this name (case-insensitive), or null when none or several. */
    public static function findUniqueByName(?string $name): ?self
    {
        return self::findUniqueBy('name', $name);
    }

    /** The one school with this email (case-insensitive), or null when none or several. */
    public static function findUniqueByEmail(?string $email): ?self
    {
        return self::findUniqueBy('email', $email);
    }

    /** Case-insensitive uniqueness scope for validation: other schools with this value. */
    public static function whereSameIgnoringCase(string $column, string $value, ?int $exceptId = null): \Illuminate\Database\Eloquent\Builder
    {
        return self::query()
            ->whereRaw('LOWER('.$column.') = LOWER(?)', [trim($value)])
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId));
    }

    private static function findUniqueBy(string $column, ?string $value): ?self
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $matches = self::whereSameIgnoringCase($column, $value)->limit(2)->get();

        if ($matches->count() > 1) {
            Log::warning('Ambiguous school '.$column.' lookup refused (duplicate legacy rows)', [
                'column' => $column,
                'school_ids' => $matches->pluck('id')->all(),
            ]);

            return null;
        }

        return $matches->first();
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function subcategories()
    {
        return $this->hasMany(Subcategory::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function academicSessions(): HasMany
    {
        return $this->hasMany(AcademicSession::class)->orderByDesc('name');
    }

    /** The school's class ladder, in progression order. */
    public function classLevels(): HasMany
    {
        return $this->hasMany(ClassLevel::class)->orderBy('position')->orderBy('id');
    }

    public function studentPromotions(): HasMany
    {
        return $this->hasMany(StudentPromotion::class);
    }

    public function academicTerms(): HasMany
    {
        return $this->hasMany(AcademicTerm::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /** The admin-selected term the dashboard and payment page default to. */
    public function currentTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicTerm::class, 'current_academic_term_id');
    }

    /** Has the school set up any roster? Decides whether the payment page requires a student. */
    public function requiresStudentOnPayment(): bool
    {
        return $this->students()->exists();
    }

    /**
     * The school's logo row (H3). Lives in its own table so the (up to ~1.4 MB
     * of base64) image is never dragged along with the School itself, which is
     * loaded on nearly every request.
     */
    public function logo(): HasOne
    {
        return $this->hasOne(SchoolLogo::class);
    }

    /** Memoised per instance: the layout asks several times per request. */
    private ?bool $logoExists = null;

    public function hasLogo(): bool
    {
        if ($this->relationLoaded('logo')) {
            return $this->logo !== null;
        }

        return $this->logoExists ??= $this->logo()->exists();
    }

    /** Forget the memoised answer after the logo row changes. */
    public function forgetLogoState(): void
    {
        $this->logoExists = null;
        $this->unsetRelation('logo');
    }

    /**
     * Public URL that streams the logo, or null. Safe for parents and emails.
     * The `v` query string is a cache-buster: it changes with the school row,
     * which every logo change touches, so a replaced logo is fetched afresh even
     * though the URL is otherwise stable and cached for a day.
     */
    public function logoUrl(): ?string
    {
        return $this->hasLogo() ? route('school.logo', ['school' => $this->slug, 'v' => $this->updated_at?->timestamp]) : null;
    }

    /**
     * The logo as a data: URI, for renderers that cannot fetch over HTTP (PDF).
     */
    public function logoDataUri(): ?string
    {
        return $this->hasLogo() ? $this->logo?->dataUri() : null;
    }

    /**
     * The public page parents pay on. The only URL that is ever shared or encoded
     * in a QR. Canonical form (/pay/{school}); the legacy /s/{school}/payment URL
     * keeps working for links already in the wild.
     */
    public function paymentUrl(): string
    {
        return route('public.payment', ['school' => $this->slug]);
    }
}
