<?php

namespace App\Models;

use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
        'logo_path',
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

    /** Memoised per instance: the layout asks several times per request. */
    private ?bool $logoExists = null;

    public function hasLogo(): bool
    {
        if ($this->logo_path === null) {
            return false;
        }

        return $this->logoExists ??= Storage::disk('local')->exists($this->logo_path);
    }

    /** Public URL that streams the logo, or null. Safe for parents and emails. */
    public function logoUrl(): ?string
    {
        return $this->hasLogo() ? route('school.logo', ['school' => $this->slug, 'v' => $this->updated_at?->timestamp]) : null;
    }

    /**
     * The logo as a data: URI, for renderers that cannot fetch over HTTP (PDF).
     */
    public function logoDataUri(): ?string
    {
        if (! $this->hasLogo()) {
            return null;
        }

        $disk = Storage::disk('local');
        $mime = $disk->mimeType($this->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($this->logo_path));
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
