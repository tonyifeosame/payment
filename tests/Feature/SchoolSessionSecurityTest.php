<?php

namespace Tests\Feature;

use App\Models\School;
use App\Support\SchoolSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * H6 — the school-admin session lifecycle: login regenerates the session id,
 * logout invalidates it, a password change keeps only the changing session and
 * revokes every other one, a password reset revokes them all — and none of it
 * can move a session from one school to another.
 */
class SchoolSessionSecurityTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        RateLimiter::clear('admin-login');
        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
        $this->beta = $this->makeSchool('Beta School', 'beta', ['email' => 'beta@example.test']);
    }

    private function login(string $name = 'Alpha School', string $password = 'password123')
    {
        return $this->post('/admin/login', ['name' => $name, 'password' => $password]);
    }

    /** The values a second browser (another device) signed in earlier would hold. */
    private function otherDeviceSession(School $school): array
    {
        return SchoolSession::payloadFor($school);
    }

    /**
     * Behave like a browser: send back the session cookie from the previous
     * response, so the session id is carried across requests (without this the
     * test client starts a new session on every request, which would hide both a
     * fixation and its fix).
     */
    private function browser(): static
    {
        return $this->withCookie(session()->getName(), session()->getId());
    }

    // =====================================================================
    // 10–11. login
    // =====================================================================

    public function test_successful_login_regenerates_the_session_id_and_keeps_only_the_school_context(): void
    {
        // A session id known before login (planted by an attacker, or simply the
        // guest session) must not be the id of the authenticated session.
        $this->get('/admin/login')->assertOk();
        $planted = session()->getId();
        $this->browser()->get('/admin/login')->assertOk();
        $this->assertSame($planted, session()->getId(), 'a browser keeps its guest session id between requests');
        $this->browser()->login('Alpha School', 'wrong')->assertRedirect();
        $this->assertSame($planted, session()->getId(), 'a failed login keeps the guest session id');

        $this->browser()->login()->assertRedirect('/admin/alpha/dashboard');

        $authenticated = session()->getId();
        $this->assertNotSame($planted, $authenticated, 'the session id must change on login');
        $this->assertSame($this->alpha->id, session(SchoolSession::ID));
        $this->assertSame(SchoolSession::fingerprint($this->alpha), session(SchoolSession::FINGERPRINT));
        $this->assertStringNotContainsString($this->alpha->admin_password, json_encode(session()->all()), 'the password hash itself is never in the session');
        $this->browser()->get('/admin/alpha/dashboard')->assertOk()->assertSee('Alpha School');

        // The planted id, presented from another context after login, is not signed
        // in: the old session was destroyed, not merely renamed. (flushSession()
        // empties the test client's in-memory store, so only the cookie counts.)
        $this->flushSession();
        $this->withCookie(session()->getName(), $planted)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
    }

    public function test_failed_login_does_not_authenticate_or_change_tenant_context(): void
    {
        $this->login('Alpha School', 'wrong')->assertRedirect()->assertSessionHas('error', 'Invalid school name or password.');
        $this->assertNull(session(SchoolSession::ID));
        $this->assertNull(session(SchoolSession::FINGERPRINT));
        $this->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');

        // Signed in as beta, a failed attempt at alpha leaves beta signed in — as beta.
        $this->withSession($this->otherDeviceSession($this->beta));
        $this->login('Alpha School', 'wrong')->assertRedirect();
        $this->assertSame($this->beta->id, session(SchoolSession::ID));
        $this->get('/admin/beta/dashboard')->assertOk();
        $this->get('/admin/alpha/dashboard')->assertNotFound();
    }

    // =====================================================================
    // 12–13, 19. logout
    // =====================================================================

    public function test_logout_invalidates_the_session_and_rotates_the_csrf_token(): void
    {
        $this->login()->assertRedirect('/admin/alpha/dashboard');
        $id = session()->getId();
        $token = session()->token();
        $this->browser()->get('/admin/alpha/dashboard')->assertOk();
        $this->assertSame($id, session()->getId());

        $this->browser()->post('/admin/logout')->assertRedirect('/admin/login')->assertSessionHas('success', 'Logged out.');

        $this->assertNotSame($id, session()->getId(), 'logout must not keep the session id');
        $this->assertNotSame($token, session()->token(), 'logout must rotate the CSRF token');
        $this->assertNull(session(SchoolSession::ID));
        $this->assertNull(session(SchoolSession::FINGERPRINT));
        $this->browser()->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
        $this->browser()->get('/admin/alpha/transactions')->assertRedirect('/admin/login');
        $this->browser()->get('/admin')->assertRedirect('/admin/login');
        // The logged-out session id, presented again, is not signed in either.
        $this->flushSession();
        $this->withCookie(session()->getName(), $id)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
        $this->withCookie(session()->getName(), $id)->get('/admin/alpha/payouts')->assertRedirect('/admin/login');

        // Logging in again afterwards works (a fresh session, fresh token).
        $this->browser()->login()->assertRedirect('/admin/alpha/dashboard');
        $this->browser()->get('/admin/alpha/dashboard')->assertOk();
    }

    public function test_the_csrf_token_is_valid_after_login_regeneration(): void
    {
        $this->login()->assertRedirect('/admin/alpha/dashboard');
        // A state-changing request with the regenerated session's token succeeds.
        $this->post('/admin/alpha/categories', ['name' => 'After Login', '_token' => session()->token()])->assertRedirect('/admin/alpha/categories');
        $this->assertDatabaseHas('categories', ['school_id' => $this->alpha->id, 'name' => 'After Login']);
    }

    // =====================================================================
    // 14–16, 21. password change
    // =====================================================================

    public function test_password_change_keeps_this_session_and_revokes_every_other_session_of_the_school(): void
    {
        $otherDevice = $this->otherDeviceSession($this->alpha); // signed in on a second device earlier

        $this->login()->assertRedirect('/admin/alpha/dashboard');
        $idBefore = session()->getId();

        $this->browser()->put('/admin/alpha/settings/password', ['current_password' => 'password123', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])
            ->assertRedirect('/admin/alpha/settings#security')->assertSessionHas('success', 'Your password has been changed.');

        // This session: still signed in, as alpha, under a new id and the new fingerprint.
        $this->assertNotSame($idBefore, session()->getId());
        $this->assertSame($this->alpha->id, session(SchoolSession::ID));
        $this->assertSame(SchoolSession::fingerprint($this->alpha->fresh()), session(SchoolSession::FINGERPRINT));
        $this->browser()->get('/admin/alpha/settings')->assertOk()->assertSee('Your password has been changed.');
        $this->browser()->get('/admin/alpha/dashboard')->assertOk();
        // The pre-change id no longer works either (destroyed on regenerate).
        $this->flushSession();
        $this->withCookie(session()->getName(), $idBefore)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');

        // The other device: revoked on its next request, with a reason.
        $this->flushSession();
        $this->withSession($otherDevice)->get('/admin/alpha/dashboard')
            ->assertRedirect('/admin/login')->assertSessionHas('error', 'Your password was changed. Please log in again.');
        $this->assertNull(session(SchoolSession::ID));
        $this->withSession($otherDevice)->get('/admin')->assertRedirect('/admin/login');
        $this->withSession($otherDevice)->get('/admin/alpha/transactions/export')->assertRedirect('/admin/login');

        // Old password no longer works; the new one does.
        $this->flushSession();
        $this->login('Alpha School', 'password123')->assertSessionHas('error');
        $this->assertNull(session(SchoolSession::ID));
        $this->login('Alpha School', 'brand-new-secret-9')->assertRedirect('/admin/alpha/dashboard');
        $this->assertSame($this->alpha->id, session(SchoolSession::ID));
    }

    public function test_a_failed_password_change_revokes_nothing(): void
    {
        $otherDevice = $this->otherDeviceSession($this->alpha);
        $this->login()->assertRedirect();
        $id = session()->getId();

        $this->browser()->put('/admin/alpha/settings/password', ['current_password' => 'wrong', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])
            ->assertRedirect('/admin/alpha/settings#security')->assertSessionHasErrors(['current_password'], null, 'password');

        $this->assertSame($id, session()->getId(), 'a failed change does not rotate the session');
        $this->browser()->get('/admin/alpha/dashboard')->assertOk();
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
        $this->flushSession();
        $this->withSession($otherDevice)->get('/admin/alpha/dashboard')->assertOk();
    }

    public function test_password_change_throttle_is_unchanged(): void
    {
        $this->login()->assertRedirect();
        $bad = ['current_password' => 'wrong', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'];
        for ($i = 0; $i < 5; $i++) {
            $this->put('/admin/alpha/settings/password', $bad)->assertRedirect();
        }
        $this->put('/admin/alpha/settings/password', $bad)->assertStatus(429);
        // Still signed in as alpha (throttling is not a logout).
        $this->get('/admin/alpha/dashboard')->assertOk();
    }

    // =====================================================================
    // 17, 22. password reset
    // =====================================================================

    public function test_password_reset_revokes_existing_sessions_and_signs_nobody_in(): void
    {
        $existing = $this->otherDeviceSession($this->alpha); // a session that was signed in before the reset

        $this->post('/admin/forgot-password', ['email' => 'alpha@example.test'])->assertRedirect()->assertSessionHas('status');
        Mail::assertSent(\App\Mail\SchoolPasswordResetMail::class, 1);
        $token = Password::createToken($this->alpha);

        // The resetting browser happened to hold a signed-in session too.
        $this->withSession($existing);
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'alpha@example.test', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])
            ->assertRedirect('/admin/login')->assertSessionHas('status', 'Your password has been reset successfully.');

        $this->assertNull(session(SchoolSession::ID), 'a reset signs nobody in');
        $this->assertTrue(Hash::check('brand-new-secret-9', $this->alpha->fresh()->admin_password));
        $this->assertFalse(Password::broker()->tokenExists($this->alpha->fresh(), $token), 'the token is single-use');

        // The pre-reset session, presented from another device, is revoked.
        $this->flushSession();
        $this->withSession($existing)->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
        $this->assertNull(session(SchoolSession::ID));

        // Login with the new password works.
        $this->flushSession();
        $this->login('Alpha School', 'brand-new-secret-9')->assertRedirect('/admin/alpha/dashboard');
    }

    public function test_reset_token_rules_are_unchanged(): void
    {
        $token = Password::createToken($this->alpha);

        // Wrong email for the token, bad token, weak password: all refused.
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'beta@example.test', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])->assertSessionHasErrors('email');
        $this->post('/admin/reset-password', ['token' => 'nope', 'email' => 'alpha@example.test', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])->assertSessionHasErrors('email');
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'alpha@example.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
        $this->assertTrue(Hash::check('password123', $this->beta->fresh()->admin_password));

        // Unknown email: the same neutral message and no mail.
        $this->post('/admin/forgot-password', ['email' => 'nobody@example.test'])->assertRedirect()->assertSessionHas('status');
        Mail::assertNothingSent();
    }

    // =====================================================================
    // 18. tenant boundaries through every lifecycle change
    // =====================================================================

    public function test_authentication_changes_never_cross_tenants(): void
    {
        $betaDevice = $this->otherDeviceSession($this->beta);

        // Alpha changes its password: beta's session is untouched.
        $this->login()->assertRedirect('/admin/alpha/dashboard');
        $this->put('/admin/alpha/settings/password', ['current_password' => 'password123', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])->assertRedirect();
        $this->flushSession();
        $this->withSession($betaDevice)->get('/admin/beta/dashboard')->assertOk()->assertSee('Beta School');
        $this->assertTrue(Hash::check('password123', $this->beta->fresh()->admin_password));

        // Alpha logs out: beta's session is untouched.
        $this->flushSession();
        $this->login('Alpha School', 'brand-new-secret-9')->assertRedirect();
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->flushSession();
        $this->withSession($betaDevice)->get('/admin/beta/dashboard')->assertOk();

        // Alpha's reset: beta's session is untouched.
        $token = Password::createToken($this->alpha);
        $this->flushSession();
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'alpha@example.test', 'password' => 'another-secret-10', 'password_confirmation' => 'another-secret-10'])->assertRedirect('/admin/login');
        $this->flushSession();
        $this->withSession($betaDevice)->get('/admin/beta/dashboard')->assertOk();

        // A session can never present alpha's fingerprint for beta's id.
        $this->flushSession();
        $this->withSession([SchoolSession::ID => $this->beta->id, SchoolSession::FINGERPRINT => SchoolSession::fingerprint($this->alpha->fresh())])
            ->get('/admin/beta/dashboard')->assertRedirect('/admin/login');
        $this->assertNull(session(SchoolSession::ID));

        // A session with the right id but no fingerprint (pre-H6) is not signed in.
        $this->flushSession();
        $this->withSession([SchoolSession::ID => $this->beta->id])->get('/admin/beta/dashboard')->assertRedirect('/admin/login');

        // Signed in as beta, alpha's pages are still 404, and the PWA entry goes to beta.
        $this->flushSession();
        $this->login('Beta School')->assertRedirect('/admin/beta/dashboard');
        $this->get('/admin/alpha/dashboard')->assertNotFound();
        $this->get('/admin')->assertRedirect('/admin/beta/dashboard');
    }

    public function test_a_revoked_session_no_longer_authorises_receipts_or_the_admin_payment_page(): void
    {
        $paid = $this->makeSuccessfulTransaction($this->alpha);
        $stale = $this->otherDeviceSession($this->alpha);
        $this->alpha->forceFill(['admin_password' => Hash::make('changed-elsewhere-1')])->save();

        $this->withSession($stale)->get("/payment/receipt/{$paid->id}")->assertNotFound();
        $this->withSession($stale)->get('/payment')->assertRedirect('/admin/login');
    }

    // =====================================================================
    // 20. login throttle
    // =====================================================================

    public function test_login_throttle_is_unchanged(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->login('Alpha School', 'wrong')->assertRedirect();
        }
        $this->login('Alpha School', 'password123')->assertStatus(429);
        $this->assertNull(session(SchoolSession::ID));
    }
}
