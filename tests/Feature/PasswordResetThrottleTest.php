<?php

namespace Tests\Feature;

use App\Mail\SchoolPasswordResetMail;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Hardening the password-reset pair, which were the last unauthenticated
 * endpoints in the app with no limit of any kind.
 *
 * Both are reachable by anyone: the request side sends mail to an address the
 * caller names, and the reset side accepts a token. Three things go wrong
 * without a limit, and none of them is token brute force — the token is 64
 * random hex characters:
 *
 *   1. Password::createToken() deletes the school's existing token before
 *      inserting the new one, so repeated requests invalidate the link already
 *      sitting in the admin's inbox. Unbounded, that denies a school its
 *      recovery path for as long as the caller keeps asking.
 *   2. Unbounded mail to a customer's address, on our sending reputation.
 *   3. The reset endpoint answered "no such school" and "bad token" with
 *      different messages — an enumeration oracle answerable with junk tokens.
 */
class PasswordResetThrottleTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const REQUEST_PER_HOUR = 5;

    private const RESET_PER_HOUR = 10;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
        $this->beta = $this->makeSchool('Beta School', 'beta', ['email' => 'beta@example.test']);
    }

    /** One address, as Render's edge would forward it. */
    private static function viaProxy(string $client): array
    {
        return ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => $client, 'HTTP_X_FORWARDED_PROTO' => 'https'];
    }

    private function askForLink(string $email = 'alpha@example.test', array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->post('/admin/forgot-password', ['email' => $email]);
    }

    private function submitReset(string $token, string $email = 'alpha@example.test', array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->post('/admin/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => 'brand-new-secret-9',
            'password_confirmation' => 'brand-new-secret-9',
        ]);
    }

    /** The reset tokens actually emailed so far, oldest first. */
    private function emailedTokens(): array
    {
        $tokens = [];

        Mail::assertSent(SchoolPasswordResetMail::class, function (SchoolPasswordResetMail $mail) use (&$tokens) {
            $tokens[] = basename((string) parse_url($mail->resetLink, PHP_URL_PATH));

            return true;
        });

        return $tokens;
    }

    // ------------------------------------------------------------ the limits

    public function test_five_link_requests_an_hour_are_allowed_and_the_sixth_is_refused(): void
    {
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            $this->askForLink()->assertRedirect()->assertSessionHas('status');
        }

        $response = $this->askForLink()->assertStatus(429);

        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter, 'the 429 carries no Retry-After header');
        $this->assertGreaterThan(0, (int) $retryAfter);
    }

    public function test_ten_reset_submissions_an_hour_are_allowed_and_the_eleventh_is_refused(): void
    {
        for ($i = 1; $i <= self::RESET_PER_HOUR; $i++) {
            $this->submitReset('not-a-real-token')->assertSessionHasErrors('email');
        }

        $this->submitReset('not-a-real-token')->assertStatus(429);
        $this->assertNotNull($this->submitReset('x')->headers->get('Retry-After'));
    }

    public function test_a_throttled_link_request_sends_no_mail_and_mints_no_token(): void
    {
        // Spaced past the broker's own throttle window so every one of the five
        // really does mint and send — otherwise the guard, not the limiter, would
        // be what stopped the sixth.
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            if ($i > 1) {
                $this->travel(61)->seconds();
            }
            $this->askForLink()->assertRedirect();
        }

        Mail::assertSent(SchoolPasswordResetMail::class, self::REQUEST_PER_HOUR);
        $emailed = $this->emailedTokens();
        $live = end($emailed);
        $this->assertTrue(Password::broker()->tokenExists($this->alpha, $live));

        $this->travel(61)->seconds();
        $this->askForLink()->assertStatus(429);

        // Nothing was sent and the live link still works: the throttled request
        // never reached the controller.
        Mail::assertSent(SchoolPasswordResetMail::class, self::REQUEST_PER_HOUR);
        $this->assertTrue(Password::broker()->tokenExists($this->alpha, $live), 'a throttled request still rotated the token');
    }

    public function test_the_windows_reset_after_an_hour(): void
    {
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            $this->askForLink()->assertRedirect();
        }
        $this->askForLink()->assertStatus(429);

        for ($i = 1; $i <= self::RESET_PER_HOUR; $i++) {
            $this->submitReset('not-a-real-token')->assertSessionHasErrors('email');
        }
        $this->submitReset('not-a-real-token')->assertStatus(429);

        $this->travel(61)->minutes();

        $this->askForLink()->assertRedirect()->assertSessionHas('status');
        $this->submitReset('not-a-real-token')->assertSessionHasErrors('email');
    }

    // ------------------------------------------------- token preservation (4)

    public function test_a_second_request_inside_the_broker_window_keeps_the_live_token(): void
    {
        $this->askForLink()->assertRedirect();
        Mail::assertSent(SchoolPasswordResetMail::class, 1);
        $first = $this->emailedTokens()[0];
        $this->assertTrue(Password::broker()->tokenExists($this->alpha, $first));

        // Immediately again, inside config('auth.passwords.users.throttle'): the
        // caller gets the same neutral answer, but the link already in the
        // admin's inbox is not replaced and no second mail goes out.
        $this->askForLink()->assertRedirect()->assertSessionHas('status');

        Mail::assertSent(SchoolPasswordResetMail::class, 1);
        $this->assertTrue(Password::broker()->tokenExists($this->alpha, $first), 'the live token was rotated inside the broker throttle window');

        // Past the window a genuine re-request still works, and supersedes it.
        $this->travel(61)->seconds();
        $this->askForLink()->assertRedirect();

        Mail::assertSent(SchoolPasswordResetMail::class, 2);
        $this->assertFalse(Password::broker()->tokenExists($this->alpha, $first), 'a legitimate re-request no longer supersedes the old token');
        $this->assertTrue(Password::broker()->tokenExists($this->alpha, $this->emailedTokens()[1]));
    }

    // ----------------------------------------------- enumeration protection (3)

    public function test_an_unknown_school_and_a_bad_token_are_indistinguishable(): void
    {
        $unknown = $this->submitReset('not-a-real-token', 'nobody@example.test');
        $knownWithBadToken = $this->submitReset('not-a-real-token', 'alpha@example.test');

        $unknown->assertSessionHasErrors('email');
        $knownWithBadToken->assertSessionHasErrors('email');

        $this->assertSame(
            $unknown->getSession()->get('errors')->get('email'),
            $knownWithBadToken->getSession()->get('errors')->get('email'),
            'the reset endpoint still says whether the email belongs to a school'
        );
        $this->assertSame(['This password reset link is invalid or has expired.'], $unknown->getSession()->get('errors')->get('email'));

        // Neither attempt changed a password.
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
    }

    public function test_the_link_request_stays_neutral_for_an_unknown_email(): void
    {
        $known = $this->askForLink('alpha@example.test');
        $this->travel(61)->seconds();
        $unknown = $this->askForLink('nobody@example.test');

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        Mail::assertSent(SchoolPasswordResetMail::class, 1); // only the real school got mail
    }

    // ------------------------------------------------------ mail failure (5)

    public function test_an_smtp_failure_is_reported_and_answered_neutrally(): void
    {
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP is down'));

        $this->askForLink()
            ->assertRedirect()
            ->assertSessionHas('status', 'If your email is in our system, you will receive a password reset link.')
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------- the happy path

    public function test_a_legitimate_reset_within_the_limits_still_works(): void
    {
        $this->askForLink()->assertRedirect();
        $token = $this->emailedTokens()[0];

        $this->submitReset($token)
            ->assertRedirect('/admin/login')
            ->assertSessionHas('status', 'Your password has been reset successfully.');

        $this->assertTrue(Hash::check('brand-new-secret-9', $this->alpha->fresh()->admin_password));
        $this->assertFalse(Password::broker()->tokenExists($this->alpha->fresh(), $token), 'the token is still single-use');
    }

    // --------------------------------------------------------- key separation

    public function test_different_client_ips_have_independent_buckets(): void
    {
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            $this->askForLink('alpha@example.test', self::viaProxy('203.0.113.9'))->assertRedirect();
        }
        $this->askForLink('alpha@example.test', self::viaProxy('203.0.113.9'))->assertStatus(429);

        $this->askForLink('alpha@example.test', self::viaProxy('198.51.100.7'))->assertRedirect();
    }

    public function test_the_forwarded_client_ip_is_the_key_behind_the_proxy(): void
    {
        // Every request arrives from Render's edge with the same REMOTE_ADDR;
        // only X-Forwarded-For tells the callers apart.
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            $this->askForLink('alpha@example.test', self::viaProxy('203.0.113.9'))->assertRedirect();
        }
        $this->askForLink('alpha@example.test', self::viaProxy('203.0.113.9'))->assertStatus(429);

        // Someone else behind the same edge is not locked out with them...
        $this->askForLink('alpha@example.test', self::viaProxy('198.51.100.7'))->assertRedirect();

        // ...and the proxy's own address was never the key.
        $this->askForLink('alpha@example.test', ['REMOTE_ADDR' => '10.0.0.1'])->assertRedirect();
    }

    public function test_the_request_and_reset_buckets_are_independent_of_each_other(): void
    {
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            $this->askForLink()->assertRedirect();
        }
        $this->askForLink()->assertStatus(429);

        // Asking for a link is closed; submitting a reset is not.
        $this->submitReset('not-a-real-token')->assertSessionHasErrors('email');
    }

    public function test_the_neighbouring_throttles_are_untouched(): void
    {
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            $this->askForLink()->assertRedirect();
        }
        for ($i = 1; $i <= self::RESET_PER_HOUR; $i++) {
            $this->submitReset('not-a-real-token')->assertSessionHasErrors('email');
        }
        $this->askForLink()->assertStatus(429);
        $this->submitReset('not-a-real-token')->assertStatus(429);

        // Each of these keeps its own bucket. They are driven with input that
        // fails on purpose — the point is that the status is not 429.
        $this->assertNotSame(429, $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'wrong'])->status(), 'admin-login was throttled with a reset bucket');
        $this->assertNotSame(429, $this->post('/registration', [])->status(), 'registration was throttled with a reset bucket');
        $this->assertNotSame(429, $this->post('/contact', [])->status(), 'contact was throttled with a reset bucket');
        $this->getJson('/api/banks?country=nigeria')->assertStatus(500); // no secret key configured, not a 429
    }

    public function test_only_the_posts_are_throttled_so_a_locked_out_admin_still_sees_the_form(): void
    {
        for ($i = 1; $i <= self::REQUEST_PER_HOUR; $i++) {
            $this->askForLink()->assertRedirect();
        }
        $this->askForLink()->assertStatus(429);

        $this->get('/admin/forgot-password')->assertOk();
        $this->get('/admin/reset-password/some-token?email=alpha@example.test')->assertOk();
    }

    public function test_both_reset_routes_carry_their_named_throttle(): void
    {
        $this->assertContains('throttle:5,60,password-reset-request', Route::getRoutes()->getByName('admin.password.email')->gatherMiddleware());
        $this->assertContains('throttle:10,60,password-reset', Route::getRoutes()->getByName('admin.password.update')->gatherMiddleware());

        // The forms themselves stay open.
        $this->assertNotContains('throttle:5,60,password-reset-request', Route::getRoutes()->getByName('admin.password.request')->gatherMiddleware());
        $this->assertNotContains('throttle:10,60,password-reset', Route::getRoutes()->getByName('admin.password.reset')->gatherMiddleware());
    }
}
