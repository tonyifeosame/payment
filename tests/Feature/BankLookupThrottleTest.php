<?php

namespace Tests\Feature;

use App\Support\BankLookupLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The `bank-list` and `bank-resolve` throttles in front of the two public
 * Paystack lookup helpers.
 *
 * Every request that reaches either controller is an outbound call on the
 * integration's single secret key — the one that also initialises payments and
 * pays schools out — so the limiters have to sit in front of the controller: a
 * throttled lookup must never reach Paystack. The two endpoints keep separate
 * buckets because the list is fetched once per form load while a resolve fires
 * on every corrected digit.
 *
 * Resolution is additionally capped per signed-in school, because the schema has
 * one admin password per school: several "admins" are one credential across many
 * sessions and addresses, and only a school-keyed bucket counts them as one.
 */
class BankLookupThrottleTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const BANKS = '/api/banks?country=nigeria';

    private const RESOLVE = '/api/resolve-account?account_number=0123456789&bank_code=058';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        Http::fake([
            '*/bank/resolve*' => Http::response([
                'status' => true,
                'data' => ['account_name' => 'RESOLVED SCHOOL LTD', 'account_number' => '0123456789'],
            ], 200),
            '*/bank?*' => Http::response([
                'status' => true,
                'message' => 'Banks retrieved',
                'data' => [['name' => 'Guaranty Trust Bank', 'code' => '058', 'active' => true]],
            ], 200),
        ]);
        Http::preventStrayRequests();
    }

    /** One address, as Render's edge would forward it. */
    private static function viaProxy(string $client): array
    {
        return ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => $client, 'HTTP_X_FORWARDED_PROTO' => 'https'];
    }

    private function banks(array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->getJson(self::BANKS);
    }

    private function resolve(array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->getJson(self::RESOLVE);
    }

    /** N successful lookups from one address, stepping over the minute window as needed. */
    private function repeat(string $endpoint, int $times, array $server = []): void
    {
        $perMinute = $endpoint === self::BANKS
            ? BankLookupLimiter::LIST_PER_MINUTE
            : BankLookupLimiter::RESOLVE_PER_MINUTE;

        for ($i = 1; $i <= $times; $i++) {
            if ($i > 1 && ($i - 1) % $perMinute === 0) {
                $this->travel(61)->seconds();
            }

            $response = $endpoint === self::BANKS ? $this->banks($server) : $this->resolve($server);
            $response->assertOk();
        }
    }

    // ------------------------------------------------------------- bank list

    public function test_the_bank_list_allows_twenty_a_minute_and_refuses_the_twenty_first(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->banks()->assertOk();
        }

        $this->banks()->assertStatus(429);

        // Twenty calls went out; the twenty-first never became one.
        Http::assertSentCount(20);
    }

    public function test_the_bank_list_hourly_limit_caps_attempts_across_minute_windows(): void
    {
        // Three full minute windows of twenty: the hourly ceiling, exactly.
        $this->repeat(self::BANKS, 60);

        // The minute bucket has just reset; the hour bucket is what says no.
        $this->travel(61)->seconds();
        $response = $this->banks()->assertStatus(429);
        $this->assertGreaterThan(60, (int) $response->headers->get('Retry-After'), 'the hourly bucket did not set Retry-After');

        Http::assertSentCount(60);

        $this->travel(1)->hour();
        $this->banks()->assertOk();
    }

    // ------------------------------------------------------ account resolution

    public function test_account_resolution_allows_ten_a_minute_and_refuses_the_eleventh(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resolve()->assertOk();
        }

        $this->resolve()->assertStatus(429);

        Http::assertSentCount(10);
    }

    public function test_account_resolution_hourly_limit_caps_attempts_per_ip(): void
    {
        $this->repeat(self::RESOLVE, 40);

        $this->travel(61)->seconds();
        $response = $this->resolve()->assertStatus(429);
        $this->assertGreaterThan(60, (int) $response->headers->get('Retry-After'), 'the hourly bucket did not set Retry-After');

        Http::assertSentCount(40);

        $this->travel(1)->hour();
        $this->resolve()->assertOk();
    }

    // -------------------------------------------------- the per-school bucket

    public function test_a_signed_in_school_is_capped_at_sixty_resolutions_an_hour_across_addresses(): void
    {
        $alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->actingAsSchoolAdmin($alpha);

        // Sixty spread over three addresses, twenty each — so no IP ever reaches
        // its own 40/hour and only the school bucket can be what refuses.
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $client) {
            $this->repeat(self::RESOLVE, 20, self::viaProxy($client));
        }

        $this->travel(61)->seconds();
        $this->resolve(self::viaProxy('203.0.113.4'))->assertStatus(429);
        Http::assertSentCount(60);

        // Proof it was the school and not the address: the same fourth address,
        // with no school session, is still free to look accounts up.
        $this->flushSession();
        $this->resolve(self::viaProxy('203.0.113.4'))->assertOk();
    }

    public function test_two_sessions_for_the_same_school_share_one_school_bucket(): void
    {
        $alpha = $this->makeSchool('Alpha School', 'alpha');
        $beta = $this->makeSchool('Beta School', 'beta');

        // One session from one address...
        $this->actingAsSchoolAdmin($alpha);
        $this->repeat(self::RESOLVE, 30, self::viaProxy('203.0.113.1'));

        // ...and a second, separate session for the SAME school from another.
        $this->flushSession();
        $this->actingAsSchoolAdmin($alpha);
        $this->repeat(self::RESOLVE, 30, self::viaProxy('203.0.113.2'));

        // Neither address is near its own 40/hour, and neither session did 60 on
        // its own: only a shared school bucket can refuse here.
        $this->travel(61)->seconds();
        $this->resolve(self::viaProxy('203.0.113.2'))->assertStatus(429);
        Http::assertSentCount(60);

        // Another school is a different bucket and is untouched.
        $this->flushSession();
        $this->actingAsSchoolAdmin($beta);
        $this->resolve(self::viaProxy('203.0.113.3'))->assertOk();
    }

    // ------------------------------------------------------------ separation

    public function test_different_client_ips_have_independent_ip_buckets(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resolve(self::viaProxy('203.0.113.9'))->assertOk();
        }
        $this->resolve(self::viaProxy('203.0.113.9'))->assertStatus(429);

        $this->resolve(self::viaProxy('198.51.100.7'))->assertOk();
    }

    public function test_the_bank_list_and_resolve_buckets_are_independent(): void
    {
        // Exhausting resolution leaves the bank list open...
        for ($i = 1; $i <= 10; $i++) {
            $this->resolve()->assertOk();
        }
        $this->resolve()->assertStatus(429);
        $this->banks()->assertOk();

        // ...and exhausting the bank list leaves resolution where it was.
        for ($i = 1; $i < 20; $i++) {
            $this->banks()->assertOk();
        }
        $this->banks()->assertStatus(429);

        $this->travel(61)->seconds();
        $this->resolve()->assertOk();
        $this->banks()->assertOk();
    }

    // --------------------------------------------------------- the 429 itself

    public function test_a_throttled_lookup_never_calls_paystack(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resolve()->assertOk();
        }
        for ($i = 1; $i <= 20; $i++) {
            $this->banks()->assertOk();
        }

        // A fresh fake starts a fresh recording, so anything sent from here on is
        // the throttled requests' doing.
        Http::fake();

        $this->resolve()->assertStatus(429);
        $this->banks()->assertStatus(429);

        Http::assertNothingSent();
    }

    public function test_the_429_carries_retry_after_and_the_json_shape_the_forms_render(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resolve()->assertOk();
        }

        $response = $this->resolve()->assertStatus(429);

        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter, 'the 429 carries no Retry-After header');
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);

        // Exactly PaystackController::failure()'s shape, which is what both forms
        // read: `data.ok` gates the success path and `data.error` is displayed.
        $response->assertJsonPath('ok', false);
        $this->assertIsString($response->json('error'));
        $this->assertStringContainsString('Too many account verification attempts', $response->json('error'));
        $response->assertJsonMissingPath('account_name');

        for ($i = 1; $i <= 20; $i++) {
            $this->banks()->assertOk();
        }
        $listResponse = $this->banks()->assertStatus(429);
        $listResponse->assertJsonPath('ok', false);
        $this->assertStringContainsString('Too many bank-list requests', $listResponse->json('error'));
        $listResponse->assertJsonMissingPath('banks');
        $this->assertNotNull($listResponse->headers->get('Retry-After'));
    }

    public function test_the_minute_windows_reset_after_their_window(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->resolve()->assertOk();
        }
        $this->resolve()->assertStatus(429);

        for ($i = 1; $i <= 20; $i++) {
            $this->banks()->assertOk();
        }
        $this->banks()->assertStatus(429);

        $this->travel(61)->seconds();

        $this->resolve()->assertOk();
        $this->banks()->assertOk();
    }

    // ------------------------------------------------------------- neighbours

    public function test_the_forwarded_client_ip_is_the_key_behind_the_proxy(): void
    {
        // Every request reaches the container from Render's edge with the same
        // REMOTE_ADDR; only X-Forwarded-For tells the visitors apart.
        for ($i = 1; $i <= 10; $i++) {
            $this->resolve(self::viaProxy('203.0.113.9'))->assertOk();
        }
        $this->resolve(self::viaProxy('203.0.113.9'))->assertStatus(429);

        // Someone else behind the same edge is not locked out with them...
        $this->resolve(self::viaProxy('198.51.100.7'))->assertOk();

        // ...and the proxy's own address was never the key.
        $this->resolve(['REMOTE_ADDR' => '10.0.0.1'])->assertOk();
    }

    public function test_the_other_throttles_are_untouched_by_an_exhausted_bank_bucket(): void
    {
        $alpha = $this->makeSchool('Alpha School', 'alpha');

        for ($i = 1; $i <= 10; $i++) {
            $this->resolve()->assertOk();
        }
        for ($i = 1; $i <= 20; $i++) {
            $this->banks()->assertOk();
        }
        $this->resolve()->assertStatus(429);
        $this->banks()->assertStatus(429);

        // Each of these keeps its own bucket. They are driven with input that
        // fails validation on purpose — the point is the status is not 429.
        $this->assertNotSame(429, $this->post('/pay/alpha/initialize', [])->status(), 'payment-initialize was throttled with a bank bucket');
        $this->assertNotSame(429, $this->post('/registration', [])->status(), 'registration was throttled with a bank bucket');
        $this->assertNotSame(429, $this->post('/admin/login', ['email' => $alpha->email, 'password' => 'wrong'])->status(), 'admin-login was throttled with a bank bucket');
        $this->postJson('/pay/alpha/student-search', ['name' => 'Ada', 'admission_number' => 'A/1'])->assertOk();
    }

    public function test_both_lookup_routes_carry_their_named_throttle(): void
    {
        $this->assertContains('throttle:bank-list', Route::getRoutes()->getByName('api.banks')->gatherMiddleware());
        $this->assertContains('throttle:bank-resolve', Route::getRoutes()->getByName('api.resolve-account')->gatherMiddleware());
    }

    public function test_the_limits_are_the_ones_the_finding_specified(): void
    {
        $this->assertSame(20, BankLookupLimiter::LIST_PER_MINUTE);
        $this->assertSame(60, BankLookupLimiter::LIST_PER_HOUR);
        $this->assertSame(10, BankLookupLimiter::RESOLVE_PER_MINUTE);
        $this->assertSame(40, BankLookupLimiter::RESOLVE_PER_HOUR);
        $this->assertSame(60, BankLookupLimiter::RESOLVE_PER_SCHOOL_PER_HOUR);
    }
}
