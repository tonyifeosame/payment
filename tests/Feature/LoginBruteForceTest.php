<?php

namespace Tests\Feature;

use App\Models\School;
use App\Support\CredentialThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M5: login was limited only per school name + IP, so rotating addresses gave a
 * guesser five fresh tries each, against a login name that is public. Two wider
 * counters now apply, and passwords set from now on are at least ten characters
 * and not known from a breach.
 */
class LoginBruteForceTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
    }

    private function login(string $name, string $password, string $ip): TestResponse
    {
        $this->flushSession();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/admin/login', ['name' => $name, 'password' => $password]);
    }

    public function test_rotating_ips_cannot_exceed_the_per_school_limit(): void
    {
        // 20 failures from 20 different addresses, one each.
        for ($i = 1; $i <= CredentialThrottle::LOGIN_ACCOUNT_MAX_ATTEMPTS; $i++) {
            $this->login('Alpha School', 'wrong-'.$i, '198.51.100.'.$i)->assertSessionHas('error', 'Invalid school name or password.');
        }

        // A fresh address, with the RIGHT password, is still refused.
        $this->login('Alpha School', 'password123', '203.0.113.200');
        $this->assertStringStartsWith('Too many failed sign-in attempts.', (string) session('error'));
        $this->assertStringEndsWith('or reset your password.', (string) session('error'));
        $this->assertNull(session('school_admin_id'));
    }

    public function test_one_ip_cannot_spray_many_school_names(): void
    {
        for ($i = 1; $i <= CredentialThrottle::LOGIN_IP_MAX_ATTEMPTS; $i++) {
            // Four tries per name stays under the per-name limit.
            $this->login('School '.intdiv($i, 4), 'wrong', '198.51.100.7');
        }

        $this->login('Alpha School', 'password123', '198.51.100.7');
        $this->assertStringStartsWith('Too many failed sign-in attempts.', (string) session('error'));

        // Another address is unaffected.
        $this->login('Alpha School', 'password123', '198.51.100.8')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_the_existing_name_and_ip_limit_still_applies(): void
    {
        for ($i = 0; $i < CredentialThrottle::MAX_ATTEMPTS; $i++) {
            $this->login('Alpha School', 'wrong', '198.51.100.9');
        }

        $this->login('Alpha School', 'password123', '198.51.100.9');
        $this->assertSame('Too many failed sign-in attempts. Please wait 60 minutes and try again.', session('error'));
    }

    public function test_a_legitimate_admin_below_the_limits_signs_in(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->login('Alpha School', 'typo', '198.51.100.10');
        }

        $this->login('Alpha School', 'password123', '198.51.100.10')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_a_password_reset_ends_an_account_wide_lockout(): void
    {
        for ($i = 1; $i <= CredentialThrottle::LOGIN_ACCOUNT_MAX_ATTEMPTS; $i++) {
            $this->login('Alpha School', 'wrong', '198.51.100.'.$i);
        }

        $this->flushSession();
        $this->post('/admin/forgot-password', ['email' => 'alpha@example.test']);
        $token = null;
        Mail::assertSent(\App\Mail\SchoolPasswordResetMail::class, function ($m) use (&$token) {
            $token = basename((string) parse_url($m->resetLink, PHP_URL_PATH));

            return true;
        });
        $this->post('/admin/reset-password', [
            'token' => $token, 'email' => 'alpha@example.test',
            'password' => 'a-fresh-passphrase-42', 'password_confirmation' => 'a-fresh-passphrase-42',
        ])->assertRedirect('/admin/login');

        $this->login('Alpha School', 'a-fresh-passphrase-42', '203.0.113.50')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_a_success_does_not_reset_the_wider_counters(): void
    {
        $this->login('Alpha School', 'wrong', '198.51.100.11');
        $this->login('Alpha School', 'password123', '198.51.100.11');

        $this->assertSame(1, \Illuminate\Support\Facades\RateLimiter::attempts(CredentialThrottle::loginAccountKey('Alpha School')));
        $this->assertSame(1, \Illuminate\Support\Facades\RateLimiter::attempts(CredentialThrottle::loginIpKey('198.51.100.11')));
        $this->assertSame(0, \Illuminate\Support\Facades\RateLimiter::attempts(CredentialThrottle::loginKey('Alpha School', '198.51.100.11')));
    }

    public function test_the_wider_keys_hold_no_name_or_ip(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('alpha', CredentialThrottle::loginAccountKey('Alpha School'));
        $this->assertSame(CredentialThrottle::loginAccountKey(' ALPHA school '), CredentialThrottle::loginAccountKey('Alpha School'));
        $this->assertStringNotContainsString('198.51.100.1', CredentialThrottle::loginIpKey('198.51.100.1'));
    }

    // ================================================================ passwords

    public function test_new_passwords_must_be_at_least_ten_characters_everywhere(): void
    {
        $short = 'nine-char';

        $this->actingAsSchoolAdmin($this->alpha)->put('/admin/alpha/settings/password', [
            'current_password' => 'password123', 'password' => $short, 'password_confirmation' => $short,
        ])->assertSessionHasErrors('password', null, 'password');

        Http::fake(['*bank/resolve*' => Http::response(['status' => true, 'data' => ['account_name' => 'X', 'account_number' => '0123456789']])]);
        config(['services.paystack.secret_key' => 'sk_test_fake']);
        $this->flushSession();
        $this->post('/registration', [
            'name' => 'Short Pw School', 'email' => 'short@example.test', 'account_number' => '0123456789',
            'bank' => 'GTB', 'bank_code' => '058', 'admin_password' => $short, 'admin_password_confirmation' => $short,
        ])->assertSessionHasErrors('admin_password');
        $this->assertFalse(School::where('slug', 'short-pw-school')->exists());

        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
    }

    public function test_existing_shorter_passwords_still_sign_in(): void
    {
        $this->alpha->forceFill(['admin_password' => Hash::make('eightchr')])->save();

        $this->login('Alpha School', 'eightchr', '198.51.100.12')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_a_breached_password_is_refused_when_the_check_is_on(): void
    {
        config(['auth.school_passwords.breach_check' => true]);
        $breached = 'correct horse battery staple';
        $hash = strtoupper(sha1($breached));
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(substr($hash, 5).":4321\r\n0000000000000000000000000000000000A:1")]);

        $this->actingAsSchoolAdmin($this->alpha)->put('/admin/alpha/settings/password', [
            'current_password' => 'password123', 'password' => $breached, 'password_confirmation' => $breached,
        ])->assertSessionHasErrors('password', null, 'password');

        // Only the 5-character prefix was sent.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/range/'.substr($hash, 0, 5)));
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
    }

    public function test_an_unbreached_password_is_accepted_when_the_check_is_on(): void
    {
        config(['auth.school_passwords.breach_check' => true]);
        Http::fake(['api.pwnedpasswords.com/range/*' => Http::response('0000000000000000000000000000000000A:1')]);

        $this->actingAsSchoolAdmin($this->alpha)->put('/admin/alpha/settings/password', [
            'current_password' => 'password123', 'password' => 'a-unique-school-passphrase', 'password_confirmation' => 'a-unique-school-passphrase',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-unique-school-passphrase', $this->alpha->fresh()->admin_password));
    }

    public function test_an_unreachable_breach_api_does_not_block_the_admin(): void
    {
        config(['auth.school_passwords.breach_check' => true]);
        Http::fake(['api.pwnedpasswords.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $this->actingAsSchoolAdmin($this->alpha)->put('/admin/alpha/settings/password', [
            'current_password' => 'password123', 'password' => 'a-unique-school-passphrase', 'password_confirmation' => 'a-unique-school-passphrase',
        ])->assertSessionHasNoErrors();
    }
}
