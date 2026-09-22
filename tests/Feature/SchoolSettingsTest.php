<?php

namespace Tests\Feature;

use App\Mail\SchoolBankDetailsChangedMail;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — school settings: profile/branding, and the guarded bank-account change.
 */
class SchoolSettingsTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['paystack_recipient_code' => 'RCP_old']);
        $this->beta = $this->makeSchool('Beta School', 'beta');
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alpha School',
            'email' => 'alpha@example.test',
            'phone' => '0801 234 5678',
            'address' => '1 Alpha Road, Lagos',
            'receipt_footer' => 'Fees are not refundable.',
        ], $overrides);
    }

    private function bank(array $overrides = []): array
    {
        return array_merge([
            'bank' => 'Zenith Bank',
            'bank_code' => '057',
            'account_number' => '9876543210',
            'current_password' => 'password123',
        ], $overrides);
    }

    public function test_settings_page_requires_the_owning_admin(): void
    {
        $this->get('/admin/alpha/settings')->assertRedirect('/admin/login');
        $this->put('/admin/alpha/settings', $this->profile(['name' => 'Hijacked']))->assertRedirect('/admin/login');

        $this->actingAsSchoolAdmin($this->beta)->get('/admin/alpha/settings')->assertNotFound();
        $this->actingAsSchoolAdmin($this->beta)->put('/admin/alpha/settings', $this->profile(['name' => 'Hijacked']))->assertNotFound();
        $this->actingAsSchoolAdmin($this->beta)->put('/admin/alpha/settings/bank', $this->bank())->assertNotFound();

        $this->assertDatabaseHas('schools', ['id' => $this->alpha->id, 'name' => 'Alpha School', 'account_number' => '0123456789']);
    }

    public function test_admin_can_update_profile_branding_and_logo(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/settings')->assertOk()->assertSee('Alpha School');

        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', $this->profile([
                'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            ]))
            ->assertRedirect('/admin/alpha/settings')
            ->assertSessionHasNoErrors();

        $school = $this->alpha->fresh();
        $this->assertSame('0801 234 5678', $school->phone);
        $this->assertSame('1 Alpha Road, Lagos', $school->address);
        $this->assertSame('Fees are not refundable.', $school->receipt_footer);
        // H3: the logo is a database row, never a file — nothing on the container
        // filesystem, so it is the same on the web, worker and cron services and
        // survives a redeploy.
        $this->assertTrue($school->hasLogo());
        $this->assertDatabaseHas('school_logos', ['school_id' => $school->id, 'mime' => 'image/png']);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The logo is publicly served and referenced on the payment page.
        $this->get('/s/alpha/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/s/alpha/payment')->assertOk()->assertSee('/s/alpha/logo', false)->assertSee('0801 234 5678');

        // And can be removed: the row goes, the school does not.
        $this->actingAsSchoolAdmin($this->alpha)->put('/admin/alpha/settings', $this->profile(['remove_logo' => 1]));
        $this->assertFalse($this->alpha->fresh()->hasLogo());
        $this->assertDatabaseMissing('school_logos', ['school_id' => $school->id]);
        $this->assertDatabaseHas('schools', ['id' => $school->id, 'name' => 'Alpha School']);
        $this->get('/s/alpha/logo')->assertNotFound();
    }

    public function test_profile_validation(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings', $this->profile(['name' => 'beta school'])) // taken, case-insensitive
            ->assertSessionHasErrors('name');

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings', $this->profile(['email' => 'nope']))
            ->assertSessionHasErrors('email');

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings', $this->profile(['logo' => UploadedFile::fake()->create('evil.php', 10, 'text/plain')]))
            ->assertSessionHasErrors('logo');

        $this->assertDatabaseHas('schools', ['id' => $this->alpha->id, 'name' => 'Alpha School']);
        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);
    }

    public function test_profile_update_cannot_touch_bank_details_or_the_slug(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', $this->profile([
                'account_number' => '1111111111',
                'bank_code' => '999',
                'account_name' => 'Attacker',
                'paystack_recipient_code' => 'RCP_attacker',
                'slug' => 'other',
                'admin_password' => 'x',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('schools', [
            'id' => $this->alpha->id,
            'slug' => 'alpha',
            'account_number' => '0123456789',
            'bank_code' => '058',
            'account_name' => 'Acct Alpha School',
            'paystack_recipient_code' => 'RCP_old',
        ]);
    }

    public function test_bank_change_requires_password_and_paystack_verification_and_resets_the_recipient(): void
    {
        Mail::fake();
        Http::fake([
            '*/bank/resolve*' => Http::response([
                'status' => true,
                'data' => ['account_name' => 'ALPHA SCHOOL LIMITED', 'account_number' => '9876543210'],
            ]),
        ]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings/bank', $this->bank())
            ->assertRedirect('/admin/alpha/settings')
            ->assertSessionHasNoErrors();

        $school = $this->alpha->fresh();
        $this->assertSame('Zenith Bank', $school->bank);
        $this->assertSame('057', $school->bank_code);
        $this->assertSame('9876543210', $school->account_number);
        // The stored name is Paystack's answer, never a typed one.
        $this->assertSame('ALPHA SCHOOL LIMITED', $school->account_name);
        // The old recipient can no longer be paid: the next payout must create a new one.
        $this->assertNull($school->paystack_recipient_code);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/bank/resolve') && $r['account_number'] === '9876543210' && $r['bank_code'] === '057');
        Mail::assertSent(SchoolBankDetailsChangedMail::class, fn ($m) => $m->hasTo('alpha@example.test'));
    }

    public function test_bank_change_is_refused_with_a_wrong_password(): void
    {
        Mail::fake();
        Http::fake(['*/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_name' => 'X']])]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings/bank', $this->bank(['current_password' => 'wrong']))
            ->assertRedirect('/admin/alpha/settings')
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseHas('schools', ['id' => $this->alpha->id, 'account_number' => '0123456789', 'paystack_recipient_code' => 'RCP_old']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_bank_change_is_refused_when_the_account_does_not_resolve(): void
    {
        Mail::fake();
        Http::fake(['*/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name. Check parameters or try again.'], 422)]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings/bank', $this->bank())
            ->assertSessionHasErrors('account_number');

        $this->assertDatabaseHas('schools', ['id' => $this->alpha->id, 'account_number' => '0123456789', 'account_name' => 'Acct Alpha School', 'paystack_recipient_code' => 'RCP_old']);
        Mail::assertNothingSent();
    }

    public function test_bank_change_is_refused_when_paystack_is_unreachable(): void
    {
        Mail::fake();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings/bank', $this->bank())
            ->assertSessionHasErrors('account_number');

        $this->assertDatabaseHas('schools', ['id' => $this->alpha->id, 'account_number' => '0123456789', 'paystack_recipient_code' => 'RCP_old']);
    }

    public function test_bank_change_validates_the_account_number_shape(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings/bank', $this->bank(['account_number' => '12345']))
            ->assertSessionHasErrors('account_number');
    }
}
