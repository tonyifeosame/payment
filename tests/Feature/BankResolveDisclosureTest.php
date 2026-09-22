<?php

namespace Tests\Feature;

use App\Models\School;
use App\Support\SchoolSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M2 (second half) — who is allowed to learn an account-holder's name.
 *
 * GET /api/resolve-account cannot be authenticated: registration is open
 * self-service and the school does not exist yet, so there is no session to
 * check. What could be restricted is not the access but the DISCLOSURE. Left
 * open, the endpoint was a public (account_number, bank_code) -> name oracle:
 * one known number against the ~25 Nigerian bank codes reveals which bank holds
 * it and whose name is on it, well inside one IP's hourly throttle budget.
 *
 * So an anonymous caller now learns only that the number resolves, which is all
 * the registration form needs — the registrant knows their own account name.
 * A signed-in admin, who already owns the school whose payout account this is,
 * still gets the full answer.
 *
 * Integrity is untouched either way: both writers re-resolve server-side and
 * store Paystack's answer, never the browser's.
 */
class BankResolveDisclosureTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const RESOLVE = '/api/resolve-account?account_number=0123456789&bank_code=058';

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_fake']);
        Http::preventStrayRequests();

        $this->school = $this->makeSchool('Alpha School', 'alpha');
    }

    private function fakeResolved(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'RESOLVED SCHOOL LTD', 'account_number' => '0123456789'],
        ], 200)]);
    }

    private function resolve(): TestResponse
    {
        return $this->getJson(self::RESOLVE);
    }

    // ------------------------------------------------------------ anonymous

    public function test_an_anonymous_caller_is_told_only_that_the_number_resolves(): void
    {
        $this->fakeResolved();

        $response = $this->resolve()->assertOk();

        $response->assertExactJson(['ok' => true, 'verified' => true]);
        $this->assertStringNotContainsString('RESOLVED SCHOOL LTD', $response->getContent());
        $this->assertStringNotContainsString('0123456789', $response->getContent());
    }

    public function test_the_account_name_is_never_in_an_anonymous_response_body(): void
    {
        $this->fakeResolved();

        $this->resolve()
            ->assertOk()
            ->assertJsonMissingPath('account_name')
            ->assertJsonMissingPath('account_number');
    }

    // -------------------------------------------------------- signed-in admin

    public function test_a_signed_in_admin_still_receives_the_full_answer(): void
    {
        $this->fakeResolved();

        $this->actingAsSchoolAdmin($this->school)
            ->resolve()
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'account_name' => 'RESOLVED SCHOOL LTD',
                'account_number' => '0123456789',
            ]);
    }

    public function test_a_session_revoked_by_a_password_change_is_treated_as_anonymous(): void
    {
        $this->fakeResolved();

        // H6: the session carries a fingerprint of the password hash it
        // authenticated under. Change the password and that session no longer
        // authenticates — so it must not keep the disclosure either.
        $session = SchoolSession::payloadFor($this->school);
        $this->school->forceFill(['admin_password' => Hash::make('a-brand-new-secret')])->save();

        $this->withSession($session)
            ->resolve()
            ->assertOk()
            ->assertExactJson(['ok' => true, 'verified' => true]);
    }

    public function test_a_session_for_a_deleted_school_is_treated_as_anonymous(): void
    {
        $this->fakeResolved();

        $session = SchoolSession::payloadFor($this->school);
        $this->school->delete();

        $this->withSession($session)
            ->resolve()
            ->assertOk()
            ->assertExactJson(['ok' => true, 'verified' => true]);
    }

    public function test_a_session_id_without_a_fingerprint_is_treated_as_anonymous(): void
    {
        $this->fakeResolved();

        // A pre-H6 session shape, or a forged one: an id and nothing else.
        $this->withSession([SchoolSession::ID => $this->school->id])
            ->resolve()
            ->assertOk()
            ->assertExactJson(['ok' => true, 'verified' => true]);
    }

    // ---------------------------------------- failures disclose no more either

    public function test_a_rejected_account_reads_the_same_for_both_callers(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => false,
            'message' => 'Could not resolve account name. Check parameters or try again.',
        ], 422)]);

        $anonymous = $this->resolve()->assertStatus(422);
        $admin = $this->actingAsSchoolAdmin($this->school)->resolve()->assertStatus(422);

        $this->assertSame($anonymous->json(), $admin->json());
        $anonymous->assertJson(['ok' => false, 'error' => 'Could not resolve account name. Check parameters or try again.']);
    }

    public function test_an_outage_is_unchanged_for_an_anonymous_caller(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response('gateway timeout', 504)]);

        $this->resolve()
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonMissingPath('account_name');
    }

    public function test_a_rejected_key_is_unchanged_for_an_anonymous_caller(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        $this->resolve()
            ->assertStatus(500)
            ->assertJson(['ok' => false, 'error' => 'Invalid key']);
    }

    public function test_validation_still_runs_before_paystack_for_both_callers(): void
    {
        Http::fake();

        $this->getJson('/api/resolve-account?account_number=123&bank_code=058')->assertStatus(422);
        $this->actingAsSchoolAdmin($this->school)
            ->getJson('/api/resolve-account?account_number=123&bank_code=058')->assertStatus(422);

        Http::assertNothingSent();
    }

    // ------------------------------------------------ integrity is unaffected

    public function test_registration_still_stores_the_server_resolved_name(): void
    {
        $this->fakeResolved();

        $this->post('/registration', [
            'name' => 'New School',
            'email' => 'new@example.test',
            'account_number' => '0123456789',
            'bank' => 'Guaranty Trust Bank',
            'bank_code' => '058',
            // Whatever the browser sends here is irrelevant — and since the
            // anonymous lookup no longer returns a name, it is now always empty.
            'account_name' => '',
            'address' => '1 Test Road',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ])->assertRedirect('/admin/new-school/dashboard');

        $created = School::where('slug', 'new-school')->firstOrFail();
        $this->assertSame('RESOLVED SCHOOL LTD', $created->account_name);
        $this->assertSame('0123456789', $created->account_number);
    }

    public function test_a_bank_change_still_stores_the_server_resolved_name(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'ALPHA SCHOOL LTD', 'account_number' => '9876543210'],
        ], 200)]);

        $this->actingAsSchoolAdmin($this->school)
            ->put('/admin/alpha/settings/bank', [
                'bank' => 'Zenith Bank',
                'bank_code' => '057',
                'account_number' => '9876543210',
                'current_password' => 'password123',
            ])->assertRedirect();

        $this->assertSame('ALPHA SCHOOL LTD', $this->school->fresh()->account_name);
        $this->assertSame('9876543210', $this->school->fresh()->account_number);
    }

    // -------------------------------------------------- the registration page

    public function test_the_registration_page_confirms_the_number_in_its_own_status_element(): void
    {
        $page = $this->get('/registration/create')->assertOk();

        // A dedicated visible status element, not the field's placeholder: the
        // confirmation has to be readable on its own terms.
        $page->assertSee('id="account_name_confirmation"', false)
            ->assertSee('role="status"', false)
            ->assertSee('✓ Account number confirmed', false);

        // The field itself stays empty and read-only, so nothing the browser
        // renders is ever submitted as account_name.
        $page->assertSee('id="account_name" name="account_name" type="text" value="" readonly', false);

        // And it no longer promises to show the account-holder name.
        $page->assertDontSee('Filled in automatically once the bank and account number are entered.');
    }

    // ------------------------------------------------ /api/banks is unchanged

    public function test_the_bank_directory_is_still_public_and_unchanged(): void
    {
        Http::fake(['*/bank?*' => Http::response([
            'status' => true,
            'data' => [['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true]],
        ], 200)]);

        $this->getJson('/api/banks?country=nigeria')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('banks.0.name', 'Guaranty Trust Bank');
    }
}
