<?php

namespace Tests\Feature;

use App\Mail\SchoolPasswordResetMail;
use App\Models\School;
use App\Support\CredentialThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L7 — credential throttles keyed on who the attempt is about, not only on the
 * client IP.
 *
 * The old route throttles counted every request per IP: successful logins used
 * up the budget, schools sharing one connection (an office, a mobile carrier's
 * NAT) locked each other out, and anonymous requests to a school's settings
 * forms counted against its signed-in admin. Now:
 *
 *   login            failed logins only, per typed school name + IP
 *   bank / password  wrong current_password only, per signed-in school
 *   reset request    every request, per email address
 *
 * all at the unchanged 5 per 60 minutes.
 */
class CredentialThrottleTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const SHARED_IP = '102.89.33.7';

    private const OTHER_IP = '102.89.33.8';

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
        $this->beta = $this->makeSchool('Beta School', 'beta', ['email' => 'beta@example.test']);
    }

    private function login(string $name, string $password, string $ip = self::SHARED_IP): TestResponse
    {
        // Every attempt starts signed out, as a separate browser would.
        $this->flushSession();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/admin/login', ['name' => $name, 'password' => $password]);
    }

    private function assertLockedOut(TestResponse $response): void
    {
        $response->assertRedirect('/admin/login');
        $this->assertStringStartsWith('Too many failed sign-in attempts.', (string) session('error'));
        $this->assertNull(session('school_admin_id'));
    }

    // ================================================================= login

    public function test_successful_logins_never_use_up_the_budget(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->login('Alpha School', 'password123')->assertRedirect('/admin/alpha/dashboard');
        }
    }

    public function test_a_successful_login_clears_earlier_failures(): void
    {
        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 4; $i++) {
                $this->login('Alpha School', 'wrong')->assertSessionHas('error', 'Invalid school name or password.');
            }
            $this->login('Alpha School', 'password123')->assertRedirect('/admin/alpha/dashboard');
        }
    }

    public function test_five_failures_lock_the_name_from_that_ip_right_password_or_not(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('Alpha School', 'wrong')->assertSessionHas('error', 'Invalid school name or password.');
        }

        $this->assertLockedOut($this->login('Alpha School', 'password123'));
        $this->assertLockedOut($this->login('Alpha School', 'wrong'));
    }

    public function test_the_lockout_message_gives_the_wait_and_keeps_only_the_school_name(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('Alpha School', 'wrong');
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::SHARED_IP])
            ->post('/admin/login', ['name' => 'Alpha School', 'password' => 'secret-typed'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasInput('name', 'Alpha School');
        $this->assertSame('Too many failed sign-in attempts. Please wait 60 minutes and try again.', session('error'));
        $this->assertArrayNotHasKey('password', session('_old_input'));

        // And the form shows it, with the name still filled in.
        $this->get('/admin/login')->assertOk()
            ->assertSee('Too many failed sign-in attempts. Please wait 60 minutes and try again.')
            ->assertSee('value="Alpha School"', false);
        $this->assertNull(session('school_admin_id'));
        $response->assertSessionMissing('school_admin_id');
    }

    public function test_schools_sharing_one_connection_do_not_lock_each_other_out(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('Alpha School', 'wrong');
        }
        $this->assertLockedOut($this->login('Alpha School', 'password123'));

        $this->login('Beta School', 'password123')->assertRedirect('/admin/beta/dashboard');
    }

    public function test_the_same_school_from_another_ip_is_not_locked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('Alpha School', 'wrong');
        }

        $this->login('Alpha School', 'password123', self::OTHER_IP)->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_the_school_name_is_matched_the_way_login_matches_it(): void
    {
        foreach (['Alpha School', 'ALPHA SCHOOL', ' alpha school ', 'Alpha school', 'aLpHa ScHoOl'] as $typed) {
            $this->login($typed, 'wrong');
        }

        $this->assertLockedOut($this->login('Alpha School', 'password123'));
    }

    public function test_an_unknown_name_is_counted_exactly_like_a_real_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('No Such School', 'wrong')->assertSessionHas('error', 'Invalid school name or password.');
        }

        // Same lockout, same message: the counter reveals nothing about which names exist.
        $this->assertLockedOut($this->login('No Such School', 'wrong'));
        $this->login('Alpha School', 'password123')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_an_incomplete_form_is_not_a_failed_login(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::SHARED_IP])
                ->post('/admin/login', ['name' => 'Alpha School'])
                ->assertSessionHasErrors('password');
            $this->flushSession();
        }

        $this->login('Alpha School', 'password123')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_the_login_lockout_expires_after_an_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('Alpha School', 'wrong');
        }
        $this->assertLockedOut($this->login('Alpha School', 'password123'));

        $this->travel(61)->minutes();

        $this->login('Alpha School', 'password123')->assertRedirect('/admin/alpha/dashboard');
    }

    // ========================================================== bank settings

    private function bankChange(School $school, string $password, array $overrides = [], string $ip = self::SHARED_IP): TestResponse
    {
        $this->flushSession();

        return $this->actingAsSchoolAdmin($school)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from("/admin/{$school->slug}/settings")
            ->put("/admin/{$school->slug}/settings/bank", array_merge([
                'bank' => 'Zenith Bank',
                'bank_code' => '057',
                'account_number' => '9876543210',
                'current_password' => $password,
            ], $overrides));
    }

    private function resolvesTo(string $accountName): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => $accountName, 'account_number' => '9876543210'],
        ])]);
    }

    public function test_five_wrong_bank_passwords_lock_the_form_right_password_or_not(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->bankChange($this->alpha, 'wrong')->assertSessionHasErrors('current_password');
        }

        $this->resolvesTo('ALPHA SCHOOL LIMITED');
        $this->bankChange($this->alpha, 'password123')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseHas('schools', ['id' => $this->alpha->id, 'account_number' => '0123456789']);
    }

    public function test_anonymous_bank_requests_never_count_against_the_admin(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->flushSession();
            $this->withServerVariables(['REMOTE_ADDR' => self::SHARED_IP])
                ->put('/admin/alpha/settings/bank', ['current_password' => 'wrong'])
                ->assertRedirect('/admin/login');
        }

        // The signed-in admin still has all five tries, answered normally.
        for ($i = 0; $i < 5; $i++) {
            $this->bankChange($this->alpha, 'wrong')->assertSessionHasErrors('current_password');
        }
        $this->bankChange($this->alpha, 'wrong')->assertStatus(429);
    }

    public function test_another_schools_bank_guesses_do_not_lock_this_school(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->bankChange($this->alpha, 'wrong')->assertSessionHasErrors('current_password');
        }
        $this->bankChange($this->alpha, 'wrong')->assertStatus(429);

        // Beta, on the same connection, is answered normally and can change its account.
        $this->bankChange($this->beta, 'wrong')->assertSessionHasErrors('current_password');
        $this->resolvesTo('BETA SCHOOL LIMITED');
        $this->bankChange($this->beta, 'password123')->assertRedirect('/admin/beta/settings')->assertSessionHasNoErrors();
        $this->assertSame('BETA SCHOOL LIMITED', $this->beta->fresh()->account_name);
    }

    public function test_the_bank_limit_is_per_school_not_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->bankChange($this->alpha, 'wrong', [], '203.0.113.'.$i);
        }

        $this->bankChange($this->alpha, 'wrong', [], '198.51.100.1')->assertStatus(429);
    }

    public function test_only_a_wrong_current_password_counts_on_the_bank_form(): void
    {
        // Validation errors: a malformed account number, a missing bank.
        for ($i = 0; $i < 6; $i++) {
            $this->bankChange($this->alpha, 'wrong', ['account_number' => 'bad'])->assertSessionHasErrors('account_number');
            $this->bankChange($this->alpha, 'password123', ['bank' => ''])->assertSessionHasErrors('bank');
        }

        // Paystack could not resolve the account (right password).
        Http::fake(['*/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name.'], 422)]);
        for ($i = 0; $i < 6; $i++) {
            $this->bankChange($this->alpha, 'password123')->assertSessionHasErrors('account_number');
        }

        // None of that counted: five wrong passwords are still answered normally.
        for ($i = 0; $i < 5; $i++) {
            $this->bankChange($this->alpha, 'wrong')->assertSessionHasErrors('current_password');
        }
        $this->bankChange($this->alpha, 'wrong')->assertStatus(429);
    }

    public function test_a_successful_bank_change_clears_the_counter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->bankChange($this->alpha, 'wrong')->assertSessionHasErrors('current_password');
        }
        $this->resolvesTo('ALPHA SCHOOL LIMITED');
        $this->bankChange($this->alpha, 'password123')->assertRedirect('/admin/alpha/settings')->assertSessionHasNoErrors();

        // Four more wrong attempts would have been the ninth: still answered normally.
        for ($i = 0; $i < 4; $i++) {
            $this->bankChange($this->alpha, 'wrong')->assertSessionHasErrors('current_password');
        }
    }

    // ====================================================== password settings

    private function passwordChange(School $school, string $current, array $overrides = [], string $ip = self::SHARED_IP): TestResponse
    {
        $this->flushSession();

        return $this->actingAsSchoolAdmin($school)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->put("/admin/{$school->slug}/settings/password", array_merge([
                'current_password' => $current,
                'password' => 'brand-new-secret-9',
                'password_confirmation' => 'brand-new-secret-9',
            ], $overrides));
    }

    public function test_five_wrong_current_passwords_lock_the_password_form_right_or_not(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->passwordChange($this->alpha, 'wrong')->assertSessionHasErrorsIn('password', ['current_password']);
        }

        $this->passwordChange($this->alpha, 'password123')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
    }

    public function test_anonymous_password_requests_never_count_against_the_admin(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->flushSession();
            $this->withServerVariables(['REMOTE_ADDR' => self::SHARED_IP])
                ->put('/admin/alpha/settings/password', ['current_password' => 'wrong'])
                ->assertRedirect('/admin/login');
        }

        for ($i = 0; $i < 5; $i++) {
            $this->passwordChange($this->alpha, 'wrong')->assertSessionHasErrorsIn('password', ['current_password']);
        }
        $this->passwordChange($this->alpha, 'wrong')->assertStatus(429);
    }

    public function test_another_schools_password_guesses_do_not_lock_this_school(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->passwordChange($this->alpha, 'wrong');
        }
        $this->passwordChange($this->alpha, 'wrong')->assertStatus(429);

        $this->passwordChange($this->beta, 'password123')->assertRedirect()->assertSessionHas('success', 'Your password has been changed.');
        $this->assertTrue(Hash::check('brand-new-secret-9', $this->beta->fresh()->admin_password));
    }

    public function test_only_a_wrong_current_password_counts_on_the_password_form(): void
    {
        // Right current password, but the new one is too short or unconfirmed.
        for ($i = 0; $i < 6; $i++) {
            $this->passwordChange($this->alpha, 'password123', ['password' => 'short', 'password_confirmation' => 'short'])
                ->assertSessionHasErrorsIn('password', ['password']);
            $this->passwordChange($this->alpha, 'password123', ['password_confirmation' => 'different-secret-9'])
                ->assertSessionHasErrorsIn('password', ['password']);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->passwordChange($this->alpha, 'wrong')->assertSessionHasErrorsIn('password', ['current_password']);
        }
        $this->passwordChange($this->alpha, 'wrong')->assertStatus(429);
    }

    public function test_a_successful_password_change_clears_the_counter(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->passwordChange($this->alpha, 'wrong');
        }
        $this->passwordChange($this->alpha, 'password123')->assertSessionHas('success', 'Your password has been changed.');
        $this->alpha->refresh();

        for ($i = 0; $i < 4; $i++) {
            $this->passwordChange($this->alpha, 'wrong')->assertSessionHasErrorsIn('password', ['current_password']);
        }
    }

    public function test_the_bank_and_password_counters_are_separate(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->passwordChange($this->alpha, 'wrong');
        }
        $this->passwordChange($this->alpha, 'wrong')->assertStatus(429);

        $this->bankChange($this->alpha, 'wrong')->assertSessionHasErrors('current_password');
        $this->login('Alpha School', 'password123')->assertRedirect('/admin/alpha/dashboard');
    }

    // ===================================================== forgot password

    private function askForLink(string $email, string $ip = self::SHARED_IP): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/admin/forgot-password', ['email' => $email]);
    }

    public function test_reset_requests_are_limited_per_email_from_any_ip(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->askForLink('alpha@example.test', '203.0.113.'.$i)->assertRedirect()->assertSessionHas('status');
        }

        $this->askForLink('alpha@example.test', '198.51.100.1')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_the_email_is_matched_case_and_space_insensitively(): void
    {
        foreach (['alpha@example.test', 'ALPHA@example.test', ' alpha@example.test ', 'Alpha@Example.Test', 'alpha@EXAMPLE.test'] as $typed) {
            $this->askForLink($typed)->assertRedirect();
        }

        $this->askForLink('alpha@example.test')->assertStatus(429);
    }

    public function test_an_unknown_email_is_limited_exactly_like_a_real_one(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->askForLink('nobody@example.test')->assertRedirect()
                ->assertSessionHas('status', 'If your email is in our system, you will receive a password reset link.');
        }

        $this->askForLink('nobody@example.test')->assertStatus(429);
        Mail::assertNothingSent();
    }

    public function test_another_email_on_the_same_connection_is_not_blocked(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->askForLink('alpha@example.test');
        }
        $this->askForLink('alpha@example.test')->assertStatus(429);

        $this->askForLink('beta@example.test')->assertRedirect()
            ->assertSessionHas('status', 'If your email is in our system, you will receive a password reset link.');
        Mail::assertSent(SchoolPasswordResetMail::class, fn ($mail) => $mail->hasTo('beta@example.test'));
    }

    public function test_an_invalid_email_is_rejected_without_counting(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->askForLink('not-an-email')->assertSessionHasErrors('email');
        }

        $this->askForLink('alpha@example.test')->assertRedirect()->assertSessionHas('status');
    }

    // ================================================================ keys

    public function test_counter_keys_hold_no_name_or_email(): void
    {
        $login = CredentialThrottle::loginKey('Alpha School', '102.89.33.7');
        $reset = CredentialThrottle::resetRequestKey('alpha@example.test');

        $this->assertStringNotContainsStringIgnoringCase('alpha', $login);
        $this->assertStringNotContainsString('102.89.33.7', $login);
        $this->assertStringNotContainsStringIgnoringCase('alpha', $reset);
        $this->assertStringNotContainsString('example.test', $reset);
        $this->assertSame(CredentialThrottle::loginKey(' ALPHA school ', '102.89.33.7'), $login);
        $this->assertNotSame(CredentialThrottle::loginKey('Alpha School', '102.89.33.8'), $login);
        $this->assertSame('bank-change:school:'.$this->alpha->id, CredentialThrottle::bankChangeKey($this->alpha));
        $this->assertNotSame(CredentialThrottle::bankChangeKey($this->alpha), CredentialThrottle::passwordChangeKey($this->alpha));
    }

    // =========================================================== neighbours

    public function test_the_credential_routes_carry_no_ip_route_throttle(): void
    {
        foreach (['admin.login.post', 'admin.password.email', 'school.settings.bank', 'school.settings.password'] as $name) {
            $throttles = array_filter(Route::getRoutes()->getByName($name)->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle'));
            $this->assertSame([], array_values($throttles), "{$name} still has a route throttle");
        }
    }

    public function test_the_neighbouring_throttles_are_unchanged(): void
    {
        $expect = [
            'admin.password.update' => 'throttle:10,60,password-reset',
            'registration.store' => 'throttle:10,60,registration',
            'contact.send' => 'throttle:5,60,contact',
            'public.payment.initialize' => 'throttle:payment-initialize',
            'school.payment.initialize' => 'throttle:payment-initialize',
            'public.payment.student-search' => 'throttle:60,1,student-search',
            'school.payment.student-search' => 'throttle:60,1,student-search',
        ];
        foreach ($expect as $name => $throttle) {
            $this->assertContains($throttle, Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $banks = collect(Route::getRoutes()->getRoutes())->flatMap(fn ($r) => $r->gatherMiddleware())->filter(fn ($m) => is_string($m));
        $this->assertTrue($banks->contains('throttle:bank-list'));
        $this->assertTrue($banks->contains('throttle:bank-resolve'));
    }

    public function test_exhausted_credential_counters_leave_the_other_throttles_alone(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('Alpha School', 'wrong');
            $this->askForLink('alpha@example.test');
        }
        $this->assertLockedOut($this->login('Alpha School', 'wrong'));
        $this->askForLink('alpha@example.test')->assertStatus(429);

        $this->assertNotSame(429, $this->withServerVariables(['REMOTE_ADDR' => self::SHARED_IP])->post('/registration', [])->status());
        $this->assertNotSame(429, $this->withServerVariables(['REMOTE_ADDR' => self::SHARED_IP])->post('/contact', [])->status());
        $this->assertNotSame(429, $this->withServerVariables(['REMOTE_ADDR' => self::SHARED_IP])->post('/admin/reset-password', [
            'token' => 'x', 'email' => 'alpha@example.test', 'password' => 'new-secret-99', 'password_confirmation' => 'new-secret-99',
        ])->status());
    }
}
