<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolRememberToken;
use App\Support\SchoolRemember;
use App\Support\SchoolSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * "Remember me" for school admins (App\Support\SchoolRemember): a persistent,
 * hashed, rotating credential that restores the SchoolSession context after the
 * session is gone, and is revoked with the rules the session already follows.
 */
class SchoolRememberMeTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
    }

    private function login(bool $remember, string $name = 'Alpha School', string $password = 'password123'): TestResponse
    {
        $data = ['name' => $name, 'password' => $password];
        if ($remember) {
            $data['remember'] = '1';
        }

        return $this->post('/admin/login', $data);
    }

    /** The decrypted remember cookie value a response set, or null. */
    private function rememberValue(TestResponse $response): ?string
    {
        $raw = $response->getCookie(SchoolRemember::cookieName(), false);
        if (! $raw || $raw->getExpiresTime() < time()) {
            return null;
        }

        return $response->getCookie(SchoolRemember::cookieName())->getValue();
    }

    private function assertRememberCookieCleared(TestResponse $response): void
    {
        $raw = $response->getCookie(SchoolRemember::cookieName(), false);
        $this->assertNotNull($raw, 'the response should clear the remember cookie');
        $this->assertLessThan(time(), $raw->getExpiresTime(), 'the remember cookie should be expired');
    }

    /** A browser whose session is gone (expired or never existed) but which still holds $value. */
    private function returningBrowser(string $value): static
    {
        $this->flushSession();
        $this->defaultCookies = [];

        return $this->withCookie(SchoolRemember::cookieName(), $value);
    }

    // ------------------------------------------------------------------ login

    public function test_login_without_remember_me_creates_no_credential_and_keeps_todays_behaviour(): void
    {
        $response = $this->login(false)->assertRedirect('/admin/alpha/dashboard');

        $this->assertNull($response->getCookie(SchoolRemember::cookieName(), false), 'no remember cookie is set');
        $this->assertSame(0, SchoolRememberToken::count());
        $this->assertSame($this->alpha->id, session(SchoolSession::ID));
        $this->assertSame(SchoolSession::fingerprint($this->alpha), session(SchoolSession::FINGERPRINT));
    }

    public function test_login_with_remember_me_creates_one_hashed_credential_and_a_cookie(): void
    {
        $response = $this->login(true)->assertRedirect('/admin/alpha/dashboard');

        $value = $this->rememberValue($response);
        $this->assertNotNull($value);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}:[0-9a-f]{64}$/', $value, 'selector:verifier only — no school id');
        [$selector, $verifier] = explode(':', $value);

        $token = SchoolRememberToken::sole();
        $this->assertSame($this->alpha->id, $token->school_id);
        $this->assertSame($selector, $token->selector);
        $this->assertSame(hash('sha256', $verifier), $token->token_hash);
        $this->assertSame(SchoolSession::fingerprint($this->alpha), $token->password_fingerprint);
        $this->assertNull($token->revoked_at);

        // The raw verifier is stored nowhere.
        $this->assertStringNotContainsString($verifier, json_encode(DB::table('school_remember_tokens')->get()));
        $this->assertStringNotContainsString($verifier, json_encode(session()->all()));
    }

    public function test_the_lifetime_comes_from_configuration(): void
    {
        config(['auth.school_remember.lifetime_days' => 7]);
        $this->freezeSecond();

        $response = $this->login(true);

        $this->assertTrue(SchoolRememberToken::sole()->expires_at->equalTo(now()->addDays(7)));
        $this->assertSame(now()->addDays(7)->getTimestamp(), $response->getCookie(SchoolRemember::cookieName(), false)->getExpiresTime());
    }

    public function test_the_cookie_is_http_only_with_the_session_cookie_same_site(): void
    {
        $cookie = $this->login(true)->getCookie(SchoolRemember::cookieName(), false);

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(config('session.same_site'), $cookie->getSameSite());
        $this->assertNotSame(config('session.cookie'), SchoolRemember::cookieName(), 'not the session cookie');
    }

    public function test_secure_follows_the_session_configuration(): void
    {
        config(['session.secure' => true]);
        $this->assertTrue($this->login(true)->getCookie(SchoolRemember::cookieName(), false)->isSecure());

        config(['session.secure' => false]);
        $this->assertFalse($this->login(true)->getCookie(SchoolRemember::cookieName(), false)->isSecure());
    }

    public function test_remember_must_be_a_boolean(): void
    {
        $this->from('/admin/login')->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123', 'remember' => 'yes-please'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('remember');
        $this->assertNull(session(SchoolSession::ID));
        $this->assertSame(0, SchoolRememberToken::count());
    }

    public function test_a_legacy_remember_on_submission_is_accepted_as_ticked(): void
    {
        // Login pages opened before deployment post the checkbox's default value "on".
        $response = $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123', 'remember' => 'on'])
            ->assertRedirect('/admin/alpha/dashboard')
            ->assertSessionHasNoErrors();

        $this->assertNotNull($this->rememberValue($response));
        $this->assertSame($this->alpha->id, SchoolRememberToken::sole()->school_id);
        $this->assertSame($this->alpha->id, session(SchoolSession::ID));
    }

    public function test_logging_in_again_with_remember_me_replaces_this_browsers_credential(): void
    {
        $first = $this->rememberValue($this->login(true));

        $second = $this->rememberValue($this->returningBrowser($first)->login(true));

        $this->assertNotSame($first, $second);
        $this->assertSame(1, SchoolRememberToken::whereNull('revoked_at')->count(), 'no duplicate credentials for one browser');
        $this->returningBrowser($first)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
    }

    public function test_logging_in_without_remember_me_revokes_this_browsers_old_credential(): void
    {
        $value = $this->rememberValue($this->login(true));

        $response = $this->returningBrowser($value)->login(false)->assertRedirect('/admin/alpha/dashboard');

        $this->assertRememberCookieCleared($response);
        $this->assertSame(0, SchoolRememberToken::whereNull('revoked_at')->count());
    }

    public function test_login_throttling_is_unaffected(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login(true, password: 'wrong')->assertSessionHas('error', 'Invalid school name or password.');
        }

        // The sixth attempt is refused even with the right password, and remembers nothing.
        $this->login(true)->assertRedirect('/admin/login')->assertSessionHas('error');
        $this->assertNull(session(SchoolSession::ID));
        $this->assertSame(0, SchoolRememberToken::count());
    }

    // ------------------------------------------------------------ restoration

    public function test_a_valid_token_restores_the_session_with_a_fresh_id_and_rotates(): void
    {
        $value = $this->rememberValue($this->login(true));
        $old = SchoolRememberToken::sole();

        // A browser whose session expired, still presenting its old session id.
        $this->flushSession();
        $this->defaultCookies = [];
        $this->get('/admin/login');
        $stale = session()->getId();
        $this->flushSession();

        $response = $this->withCookie(session()->getName(), $stale)
            ->withCookie(SchoolRemember::cookieName(), $value)
            ->get('/admin/alpha/dashboard')
            ->assertOk()
            ->assertSee('Alpha School');

        $this->assertNotSame($stale, session()->getId(), 'restoration must regenerate the session id');
        $this->assertSame($this->alpha->id, session(SchoolSession::ID));
        $this->assertSame(SchoolSession::fingerprint($this->alpha), session(SchoolSession::FINGERPRINT));

        // Rotated: the used token is revoked and a new one, with the same expiry, replaces it.
        $rotated = $this->rememberValue($response);
        $this->assertNotNull($rotated);
        $this->assertNotSame($value, $rotated);
        $this->assertNotNull($old->fresh()->revoked_at);
        $this->assertNotNull($old->fresh()->last_used_at);
        $new = SchoolRememberToken::whereNull('revoked_at')->sole();
        $this->assertTrue($new->expires_at->equalTo($old->expires_at), 'rotation does not extend the lifetime');

        // The new cookie works; the used one no longer does.
        $this->returningBrowser($rotated)->get('/admin/alpha/dashboard')->assertOk();
        $this->assertRememberCookieCleared(
            $this->returningBrowser($value)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );
    }

    public function test_the_admin_entry_point_restores_too(): void
    {
        $value = $this->rememberValue($this->login(true));

        $this->returningBrowser($value)->get('/admin')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_an_expired_token_is_rejected_and_cleared(): void
    {
        $value = $this->rememberValue($this->login(true));

        $this->travel(31)->days();

        $this->assertRememberCookieCleared(
            $this->returningBrowser($value)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );
        $this->assertNull(session(SchoolSession::ID));
    }

    public function test_a_revoked_token_is_rejected_and_cleared(): void
    {
        $value = $this->rememberValue($this->login(true));
        SchoolRememberToken::query()->update(['revoked_at' => now()]);

        $this->assertRememberCookieCleared(
            $this->returningBrowser($value)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );
        $this->assertNull(session(SchoolSession::ID));
    }

    public function test_a_tampered_verifier_is_rejected_cleared_and_revokes_the_token(): void
    {
        $value = $this->rememberValue($this->login(true));
        [$selector] = explode(':', $value);

        $this->assertRememberCookieCleared(
            $this->returningBrowser($selector.':'.str_repeat('0', 64))->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );
        $this->assertNull(session(SchoolSession::ID));
        $this->assertNotNull(SchoolRememberToken::sole()->revoked_at, 'a known selector with the wrong verifier revokes it');
    }

    public function test_malformed_or_undecryptable_cookies_are_rejected_and_cleared(): void
    {
        $this->assertRememberCookieCleared(
            $this->returningBrowser('not-a-token')->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );

        // Not encrypted by the app, so EncryptCookies cannot decrypt it.
        $this->flushSession();
        $this->defaultCookies = [];
        $this->assertRememberCookieCleared(
            $this->withUnencryptedCookie(SchoolRemember::cookieName(), str_repeat('a', 32).':'.str_repeat('b', 64))
                ->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );
        $this->assertNull(session(SchoolSession::ID));
    }

    public function test_a_token_for_one_school_never_authenticates_another(): void
    {
        $value = $this->rememberValue($this->login(true));

        // The cookie signs this browser in as Alpha, its own school, and Beta's pages stay closed.
        $this->returningBrowser($value)->get('/admin/beta/dashboard')->assertNotFound();
        $this->assertSame($this->alpha->id, session(SchoolSession::ID));
    }

    public function test_a_token_issued_under_an_old_password_is_rejected_even_if_not_revoked(): void
    {
        $value = $this->rememberValue($this->login(true));

        // A password changed outside the application's flows (e.g. a console fix).
        $this->alpha->forceFill(['admin_password' => Hash::make('changed-elsewhere')])->save();

        $this->assertRememberCookieCleared(
            $this->returningBrowser($value)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );
        $this->assertNotNull(SchoolRememberToken::sole()->revoked_at);
    }

    public function test_a_deleted_school_is_never_restored(): void
    {
        $value = $this->rememberValue($this->login(true));

        $this->alpha->delete();

        $this->assertSame(0, SchoolRememberToken::count(), 'tokens go with the school');
        $this->assertRememberCookieCleared(
            $this->returningBrowser($value)->get('/admin')->assertRedirect('/admin/login')
        );
        $this->assertNull(session(SchoolSession::ID));
    }

    // -------------------------------------------------- revocation on credentials

    public function test_a_password_change_revokes_every_remembered_browser_of_the_school(): void
    {
        $otherDevice = $this->rememberValue($this->login(true));
        $this->flushSession();
        $this->defaultCookies = [];
        $betaDevice = $this->rememberValue($this->login(true, 'Beta School'));

        // This browser: signed in with Remember me, then changes the password.
        $this->flushSession();
        $this->defaultCookies = [];
        $thisDevice = $this->rememberValue($this->login(true));
        $response = $this->withCookie(session()->getName(), session()->getId())
            ->withCookie(SchoolRemember::cookieName(), $thisDevice)
            ->put('/admin/alpha/settings/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])->assertRedirect();

        $this->assertRememberCookieCleared($response);
        $this->assertSame(0, SchoolRememberToken::where('school_id', $this->alpha->id)->whereNull('revoked_at')->count());
        $this->returningBrowser($otherDevice)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
        $this->returningBrowser($thisDevice)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');

        // Another school's remembered browser is untouched.
        $this->returningBrowser($betaDevice)->get('/admin/beta/dashboard')->assertOk();
    }

    public function test_a_password_reset_revokes_every_remembered_browser_of_the_school(): void
    {
        $value = $this->rememberValue($this->login(true));
        $token = Password::createToken($this->alpha);

        $this->flushSession();
        $this->defaultCookies = [];
        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => 'alpha@example.test',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertRedirect('/admin/login');

        $this->assertSame(0, SchoolRememberToken::whereNull('revoked_at')->count());
        $this->assertRememberCookieCleared(
            $this->returningBrowser($value)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login')
        );
    }

    public function test_logout_revokes_only_this_browsers_credential(): void
    {
        $otherDevice = $this->rememberValue($this->login(true));

        $this->flushSession();
        $this->defaultCookies = [];
        $thisDevice = $this->rememberValue($this->login(true));
        $response = $this->withCookie(session()->getName(), session()->getId())
            ->withCookie(SchoolRemember::cookieName(), $thisDevice)
            ->post('/admin/logout')
            ->assertRedirect('/admin/login');

        $this->assertRememberCookieCleared($response);
        $this->assertNull(session(SchoolSession::ID));
        $this->returningBrowser($thisDevice)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
        $this->returningBrowser($otherDevice)->get('/admin/alpha/dashboard')->assertOk();
    }
}
