<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The two lookups behind the registration form's "School Bank" dropdown and
 * "Account Name (Auto-verified)" field, plus the server-side re-verification that
 * makes the browser's answer irrelevant to what actually gets stored.
 */
class BankLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_fake']);
        Http::preventStrayRequests();
    }

    private function bankList(): array
    {
        return [
            'status' => true,
            'message' => 'Banks retrieved',
            'data' => [
                ['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true],
                ['name' => 'Zenith Bank', 'code' => '057', 'active' => true],
            ],
        ];
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New School',
            'email' => 'new@example.test',
            'account_number' => '0123456789',
            'bank' => 'Guaranty Trust Bank',
            'bank_code' => '058',
            'address' => '1 Test Road',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ], $overrides);
    }

    // ---------------------------------------------------------------- /api/banks

    public function test_bank_list_is_refused_with_a_clear_message_when_no_secret_key_is_configured(): void
    {
        config(['services.paystack.secret_key' => '']);
        Http::fake();

        $this->getJson('/api/banks?country=nigeria')
            ->assertStatus(500)
            ->assertJson(['ok' => false, 'error' => 'Paystack secret key is not configured']);

        Http::assertNothingSent();
    }

    public function test_bank_list_loads_and_is_requested_with_the_secret_key(): void
    {
        Http::fake(['*/bank?*' => Http::response($this->bankList(), 200)]);

        $this->getJson('/api/banks?country=nigeria')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'banks')
            ->assertJsonPath('banks.0.name', 'Guaranty Trust Bank')
            ->assertJsonPath('banks.0.code', '058');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.paystack.co/bank?')
            && $r->hasHeader('Authorization', 'Bearer sk_test_fake')
            && $r['country'] === 'nigeria');
    }

    public function test_bank_list_surfaces_a_rejected_key_instead_of_a_generic_error(): void
    {
        Http::fake(['*/bank?*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        $this->getJson('/api/banks?country=nigeria')
            ->assertStatus(500)
            ->assertJson(['ok' => false, 'error' => 'Invalid key']);

        // A definitive 4xx must not be hammered with retries.
        Http::assertSentCount(1);
    }

    public function test_bank_list_reports_an_outage_as_unavailable(): void
    {
        Http::fake(['*/bank?*' => Http::response('bad gateway', 502)]);

        $this->getJson('/api/banks?country=nigeria')
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    public function test_bank_list_reports_a_connection_failure_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->getJson('/api/banks?country=nigeria')
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    // ------------------------------------------------------- /api/resolve-account

    public function test_account_resolution_is_refused_when_no_secret_key_is_configured(): void
    {
        config(['services.paystack.secret_key' => '']);
        Http::fake();

        $this->getJson('/api/resolve-account?account_number=0123456789&bank_code=058')
            ->assertStatus(500)
            ->assertJson(['ok' => false, 'error' => 'Paystack secret key is not configured']);

        Http::assertNothingSent();
    }

    public function test_account_resolution_validates_its_input_before_calling_paystack(): void
    {
        Http::fake();

        $this->getJson('/api/resolve-account?account_number=123&bank_code=058')->assertStatus(422);
        $this->getJson('/api/resolve-account?account_number=0123456789')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_valid_account_returns_the_name_paystack_resolved(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'RESOLVED SCHOOL LTD', 'account_number' => '0123456789'],
        ], 200)]);

        $this->getJson('/api/resolve-account?account_number=0123456789&bank_code=058')
            ->assertOk()
            ->assertJson(['ok' => true, 'account_name' => 'RESOLVED SCHOOL LTD', 'account_number' => '0123456789']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bank/resolve')
            && $r->hasHeader('Authorization', 'Bearer sk_test_fake')
            && $r['account_number'] === '0123456789'
            && $r['bank_code'] === '058');
    }

    public function test_an_invalid_account_is_rejected_with_paystacks_reason(): void
    {
        // This is the exact shape Paystack returns for a wrong number/bank pair.
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => false,
            'message' => 'Could not resolve account name. Check parameters or try again.',
        ], 422)]);

        $this->getJson('/api/resolve-account?account_number=0000000000&bank_code=058')
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'error' => 'Could not resolve account name. Check parameters or try again.'])
            ->assertJsonMissingPath('account_name');

        Http::assertSentCount(1);
    }

    public function test_an_invalid_secret_key_is_surfaced_not_blamed_on_the_account(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        $this->getJson('/api/resolve-account?account_number=0123456789&bank_code=058')
            ->assertStatus(500)
            ->assertJson(['ok' => false, 'error' => 'Invalid key']);
    }

    public function test_account_resolution_reports_an_outage_as_unavailable(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response('gateway timeout', 504)]);

        $this->getJson('/api/resolve-account?account_number=0123456789&bank_code=058')
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonMissingPath('account_name');
    }

    public function test_account_resolution_reports_a_connection_failure_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->getJson('/api/resolve-account?account_number=0123456789&bank_code=058')
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'error' => 'Connection to verification service timed out.']);
    }

    // ------------------------------------------- registration re-verifies server-side

    public function test_registration_is_refused_when_paystack_rejects_the_account(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => false,
            'message' => 'Could not resolve account name. Check parameters or try again.',
        ], 422)]);

        $this->from('/registration/create')
            ->post('/registration', $this->registrationPayload(['account_number' => '0000000000']))
            ->assertRedirect('/registration/create')
            ->assertSessionHasErrors(['account_number' => 'Could not resolve account name. Check parameters or try again.']);

        $this->assertDatabaseMissing('schools', ['slug' => 'new-school']);
        $this->assertNull(session('school_admin_id'));
    }

    public function test_registration_is_refused_when_no_secret_key_is_configured(): void
    {
        config(['services.paystack.secret_key' => '']);
        Http::fake();

        $this->from('/registration/create')
            ->post('/registration', $this->registrationPayload())
            ->assertRedirect('/registration/create')
            ->assertSessionHasErrors('account_number');

        $this->assertDatabaseMissing('schools', ['slug' => 'new-school']);
        Http::assertNothingSent();
    }

    public function test_registration_is_refused_when_verification_is_unavailable(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response('gateway timeout', 504)]);

        $this->from('/registration/create')
            ->post('/registration', $this->registrationPayload())
            ->assertRedirect('/registration/create')
            ->assertSessionHasErrors('account_number');

        $this->assertDatabaseMissing('schools', ['slug' => 'new-school']);
    }

    public function test_registration_stores_the_resolved_name_not_the_one_the_browser_sent(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'RESOLVED SCHOOL LTD', 'account_number' => '0123456789'],
        ], 200)]);

        $this->post('/registration', $this->registrationPayload(['account_name' => 'Somebody Else']))
            ->assertRedirect('/admin/new-school/dashboard');

        $school = School::where('slug', 'new-school')->firstOrFail();
        $this->assertSame('RESOLVED SCHOOL LTD', $school->account_name);
        $this->assertSame('0123456789', $school->account_number);
        $this->assertSame('058', $school->bank_code);
    }
}
