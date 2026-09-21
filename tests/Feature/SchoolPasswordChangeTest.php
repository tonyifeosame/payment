<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The redesigned settings page and the in-session password change: what renders,
 * what the password endpoint accepts and refuses, that no password ever comes
 * back out, and that nothing crosses school boundaries.
 */
class SchoolPasswordChangeTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.paystack.secret_key' => 'sk_test_secret']);
        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['account_number' => '0123456789', 'account_name' => 'ALPHA SCHOOL LTD', 'bank' => 'GTB']);
        $this->beta = $this->makeSchool('Beta School', 'beta');
    }

    private function alpha(): static
    {
        return $this->actingAsSchoolAdmin($this->alpha);
    }

    private function change(array $overrides = [], string $slug = 'alpha')
    {
        return $this->put("/admin/{$slug}/settings/password", array_merge([
            'current_password' => 'password123',
            'password' => 'brand-new-secret-9',
            'password_confirmation' => 'brand-new-secret-9',
        ], $overrides));
    }

    public function test_settings_page_renders_the_four_sections_and_masks_the_account(): void
    {
        $page = $this->alpha()->get('/admin/alpha/settings')->assertOk();

        $page->assertSee('School settings')->assertSee('Manage your school details, payout account, receipts, and account security.')
            ->assertSee('School profile')->assertSee('Receipt settings')->assertSee('Payout account')->assertSee('Security')->assertSee('Change your password')
            ->assertSee('value="Alpha School"', false)->assertSee('Receipt footer text')
            ->assertSee('GTB')->assertSee('ALPHA SCHOOL LTD')->assertSee('Verified')
            ->assertSee('••••••6789')->assertDontSee('0123456789')
            ->assertSee('/admin/alpha/settings/password')->assertSee('/admin/alpha/settings/bank')
            ->assertSee('name="current_password"', false)->assertSee('name="password_confirmation"', false)
            ->assertDontSee('onclick="return confirm', false)
            ->assertDontSee('ervice fee')->assertDontSee('latform fee')->assertDontSee('password123');
        $this->assertSame(1, substr_count($page->getContent(), '<h1'));

        // Guests are redirected; another school's admin is refused on both reads and writes.
        $this->flushSession();
        $this->get('/admin/alpha/settings')->assertRedirect('/admin/login');
        $this->change()->assertRedirect('/admin/login');
        $this->put('/admin/alpha/settings', ['name' => 'X', 'email' => 'x@example.test'])->assertRedirect('/admin/login');
        $this->put('/admin/alpha/settings/bank', ['bank' => 'GTB', 'bank_code' => '058', 'account_number' => '0123456789', 'current_password' => 'password123'])->assertRedirect('/admin/login');
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
    }

    public function test_correct_current_password_and_valid_new_password_changes_it_and_keeps_the_session(): void
    {
        $response = $this->alpha()->from('/admin/alpha/settings')->change();

        $response->assertRedirect('/admin/alpha/settings#security')->assertSessionHas('success', 'Your password has been changed.')->assertSessionHasNoErrors();
        $school = $this->alpha->fresh();
        $this->assertTrue(Hash::check('brand-new-secret-9', $school->admin_password));
        $this->assertFalse(Hash::check('password123', $school->admin_password));
        $this->assertNotSame('brand-new-secret-9', $school->admin_password); // hashed, never plain

        // Still signed in afterwards (a change is not a reset).
        $this->assertSame($this->alpha->id, session('school_admin_id'));
        $this->get('/admin/alpha/settings')->assertOk()->assertSee('Your password has been changed.');

        // Login: the new password works, the old one no longer does.
        $this->flushSession();
        $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123'])->assertRedirect()->assertSessionHas('error');
        $this->assertNull(session('school_admin_id'));
        $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'brand-new-secret-9'])->assertRedirect('/admin/alpha/dashboard');
        $this->assertSame($this->alpha->id, session('school_admin_id'));
    }

    public function test_wrong_current_password_is_rejected_without_leaking_anything(): void
    {
        $response = $this->alpha()->from('/admin/alpha/settings')->change(['current_password' => 'not-it']);

        $response->assertRedirect('/admin/alpha/settings#security')->assertSessionHasErrorsIn('password', ['current_password']);
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));

        // No password is flashed as old input, in any form.
        $old = session('_old_input', []);
        $this->assertArrayNotHasKey('current_password', $old);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('password_confirmation', $old);

        $html = $this->get('/admin/alpha/settings')->assertOk()->getContent();
        $this->assertStringContainsString('The password you entered is incorrect.', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        foreach (['not-it', 'brand-new-secret-9', 'password123'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "Rendered settings page contains a password: {$secret}");
        }
        // The error is in its own bag: the profile form is not marked invalid.
        $this->assertStringNotContainsString('id="name-error"', $html);
    }

    public function test_confirmation_mismatch_and_weak_password_are_rejected_by_the_existing_rules(): void
    {
        $this->alpha()->from('/admin/alpha/settings')->change(['password_confirmation' => 'different-secret'])
            ->assertSessionHasErrorsIn('password', ['password']);
        $this->assertStringContainsString('do not match', session('errors')->getBag('password')->first('password'));

        $this->alpha()->from('/admin/alpha/settings')->change(['password' => 'short7!', 'password_confirmation' => 'short7!'])
            ->assertSessionHasErrorsIn('password', ['password']);

        $this->alpha()->from('/admin/alpha/settings')->change(['password' => '', 'password_confirmation' => ''])
            ->assertSessionHasErrorsIn('password', ['password']);

        $this->alpha()->from('/admin/alpha/settings')->put('/admin/alpha/settings/password', [])
            ->assertSessionHasErrorsIn('password', ['current_password', 'password']);

        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
        $this->assertArrayNotHasKey('password', session('_old_input', []));
    }

    public function test_password_change_is_tenant_scoped(): void
    {
        // Alpha's admin cannot change beta's password through beta's URL (middleware 404s).
        $this->alpha()->change([], 'beta')->assertNotFound();
        $this->assertTrue(Hash::check('password123', $this->beta->fresh()->admin_password));

        // Beta's admin cannot change alpha's.
        $this->actingAsSchoolAdmin($this->beta)->change([], 'alpha')->assertNotFound();
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));

        // Alpha's admin cannot update beta's profile or payout account either.
        $this->alpha()->put('/admin/beta/settings', ['name' => 'Hijacked', 'email' => 'x@example.test'])->assertNotFound();
        $this->alpha()->put('/admin/beta/settings/bank', ['bank' => 'GTB', 'bank_code' => '058', 'account_number' => '1111111111', 'current_password' => 'password123'])->assertNotFound();
        $this->assertDatabaseHas('schools', ['id' => $this->beta->id, 'name' => 'Beta School']);
    }

    public function test_password_change_has_its_own_throttle_bucket(): void
    {
        // Five guesses an hour per IP, like the bank form and login...
        for ($i = 0; $i < 5; $i++) {
            $this->alpha()->change(['current_password' => 'guess-'.$i])->assertRedirect()->assertSessionHasErrorsIn('password', ['current_password']);
        }
        // ...the sixth is refused before the password is checked, right or wrong.
        $this->alpha()->change()->assertStatus(429);
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));

        // Separate buckets: the bank form and login are not locked by these guesses.
        $this->alpha()->put('/admin/alpha/settings/bank', ['bank' => 'GTB', 'bank_code' => '058', 'account_number' => 'bad', 'current_password' => 'x'])->assertRedirect();
        $this->flushSession();
        $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123'])->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_profile_receipt_and_payout_account_flows_are_unchanged(): void
    {
        $this->alpha()->put('/admin/alpha/settings', ['name' => 'Alpha School', 'email' => 'new@example.test', 'phone' => '0801', 'address' => '2 Road', 'receipt_footer' => 'Thank you.'])
            ->assertRedirect('/admin/alpha/settings')->assertSessionHas('success', 'School settings saved.');
        $school = $this->alpha->fresh();
        $this->assertSame(['new@example.test', 'Thank you.', '0123456789', 'ALPHA SCHOOL LTD'], [$school->email, $school->receipt_footer, $school->account_number, $school->account_name]);
        $this->assertTrue(Hash::check('password123', $school->admin_password));

        // Invalid profile data is rejected inline and keeps the old (non-secret) input.
        $this->alpha()->from('/admin/alpha/settings')->put('/admin/alpha/settings', ['name' => '', 'email' => 'nope', 'receipt_footer' => str_repeat('x', 501)])
            ->assertRedirect('/admin/alpha/settings')->assertSessionHasErrors(['name', 'email', 'receipt_footer']);
        $this->alpha()->withSession(['_old_input' => ['email' => 'nope']])->get('/admin/alpha/settings')->assertOk()->assertSee('value="nope"', false);

        // Payout account: wrong password refused; a resolvable account is saved with the bank's name.
        Mail::fake();
        Http::fake(['*/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_name' => 'ALPHA SCHOOL LIMITED', 'account_number' => '9876543210']])]);
        $this->alpha()->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings/bank', ['bank' => 'Zenith Bank', 'bank_code' => '057', 'account_number' => '9876543210', 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('0123456789', $this->alpha->fresh()->account_number);
        Http::assertNothingSent();

        $this->alpha()->put('/admin/alpha/settings/bank', ['bank' => 'Zenith Bank', 'bank_code' => '057', 'account_number' => '9876543210', 'current_password' => 'password123'])
            ->assertRedirect('/admin/alpha/settings')->assertSessionHasNoErrors();
        $school = $this->alpha->fresh();
        $this->assertSame(['Zenith Bank', '9876543210', 'ALPHA SCHOOL LIMITED'], [$school->bank, $school->account_number, $school->account_name]);
        $this->alpha()->get('/admin/alpha/settings')->assertOk()->assertSee('••••••3210')->assertSee('ALPHA SCHOOL LIMITED')->assertDontSee('9876543210');
    }
}
