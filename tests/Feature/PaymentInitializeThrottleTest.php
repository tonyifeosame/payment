<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Support\PaymentInitializeLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The `payment-initialize` throttle: 10 submits per minute and 60 per hour per
 * client IP, one bucket shared by POST /pay/{school}/initialize and the legacy
 * POST /s/{school}/payment/initialize.
 *
 * Every submit creates a pending transaction and calls Paystack, so the limiter
 * has to sit in front of the controller: a throttled request must leave no row
 * behind and never reach the provider. It also has to be keyed by the *client*
 * IP that Render's edge forwards, not by the proxy's own address, or every
 * parent in the country would share one bucket.
 */
class PaymentInitializeThrottleTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const CANONICAL = '/pay/alpha/initialize';

    private const LEGACY = '/s/alpha/payment/initialize';

    private const CHECKOUT_URL = 'https://checkout.paystack.com/abc';

    private School $alpha;

    private Subcategory $fee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        // Allows multiple units so the submitted quantity (2) is a real, non-default
        // value the throttled response must carry back as old input.
        $this->fee = $this->makeFee($this->alpha, 'School Fees', 'Tuition', 50000, allowsQuantity: true);

        Http::fake(['*/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => self::CHECKOUT_URL],
        ])]);
    }

    /** A complete, valid checkout submit, optionally from a given client address. */
    private function submit(string $uri = self::CANONICAL, array $server = []): TestResponse
    {
        return $this->withServerVariables($server)->post($uri, [
            'email' => 'parent@example.test',
            'name' => 'Ada Parent',
            'category_id' => $this->fee->category_id,
            'subcategory_id' => $this->fee->id,
            'quantity' => 2,
        ]);
    }

    private function exhaustMinute(string $uri = self::CANONICAL, array $server = []): void
    {
        for ($i = 1; $i <= PaymentInitializeLimiter::PER_MINUTE; $i++) {
            $this->submit($uri, $server)->assertRedirect(self::CHECKOUT_URL);
        }
    }

    public function test_ten_initializations_within_a_minute_are_allowed(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->submit()->assertRedirect(self::CHECKOUT_URL);
        }

        $this->assertSame(10, Transaction::count());
        Http::assertSentCount(10);
    }

    public function test_the_eleventh_initialization_within_a_minute_gets_a_429_with_the_form_and_a_message(): void
    {
        $this->exhaustMinute();

        $response = $this->submit()->assertStatus(429);

        // Retry-After is present and points inside the minute window.
        $retryAfter = $response->headers->get('Retry-After');
        $this->assertNotNull($retryAfter, 'the 429 carries no Retry-After header');
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);

        // A human-readable reason, on the payment form, with the parent's input kept.
        $response->assertSee('Too many payment attempts');
        $response->assertSee('Your details are still filled in below');
        $response->assertSee('Alpha School');
        $response->assertSee('value="parent@example.test"', false);
        $response->assertSee('value="Ada Parent"', false);
        $response->assertSee('value="2"', false);
        $response->assertSee('value="'.$this->fee->category_id.'" selected', false);

        // The input and message were rendered into this response, not left in the
        // session where the next request (the page's logo fetch, say) would eat them.
        $response->assertSessionMissing('_old_input')->assertSessionMissing('error');
    }

    public function test_a_throttled_request_creates_no_pending_transaction(): void
    {
        $this->exhaustMinute();
        $this->assertSame(10, Transaction::count());

        $this->submit()->assertStatus(429);

        $this->assertSame(10, Transaction::count(), 'a throttled submit still created a transaction');
    }

    public function test_a_throttled_request_never_calls_paystack(): void
    {
        $this->exhaustMinute();

        // A fresh fake starts a fresh recording, so anything sent from here on is
        // the throttled request's doing.
        Http::fake();

        $this->submit()->assertStatus(429);

        Http::assertNothingSent();
    }

    public function test_the_canonical_and_legacy_urls_share_one_bucket(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->submit(self::CANONICAL)->assertRedirect(self::CHECKOUT_URL);
            $this->submit(self::LEGACY)->assertRedirect(self::CHECKOUT_URL);
        }

        // Ten in total: both spellings are now closed, in either order.
        $this->submit(self::LEGACY)->assertStatus(429);
        $this->submit(self::CANONICAL)->assertStatus(429);
        $this->assertSame(10, Transaction::count());
    }

    public function test_different_client_ips_have_independent_limits(): void
    {
        $this->exhaustMinute(self::CANONICAL, ['REMOTE_ADDR' => '198.51.100.10']);
        $this->submit(self::CANONICAL, ['REMOTE_ADDR' => '198.51.100.10'])->assertStatus(429);

        $this->submit(self::CANONICAL, ['REMOTE_ADDR' => '198.51.100.11'])->assertRedirect(self::CHECKOUT_URL);
        $this->assertSame(11, Transaction::count());
    }

    public function test_the_minute_limit_resets_after_its_window(): void
    {
        $this->exhaustMinute();
        $this->submit()->assertStatus(429);

        $this->travel(61)->seconds();

        $this->submit()->assertRedirect(self::CHECKOUT_URL);
        $this->assertSame(11, Transaction::count());
    }

    public function test_the_hourly_limit_caps_attempts_across_minute_windows_and_resets_after_an_hour(): void
    {
        // Six minute-windows of ten attempts each. Every attempt counts, not only
        // successful ones, so these deliberately fail validation (the empty form
        // bounces back with errors) to stay cheap — no transaction, no Paystack call.
        for ($window = 1; $window <= 6; $window++) {
            for ($i = 1; $i <= 10; $i++) {
                $this->post(self::CANONICAL, [])->assertRedirect()->assertSessionHasErrors('email');
            }
            $this->travel(61)->seconds();
        }

        // The minute bucket has just reset; the hour bucket is what says no.
        $response = $this->post(self::CANONICAL, [])->assertStatus(429);
        $this->assertGreaterThan(60, (int) $response->headers->get('Retry-After'), 'the hourly bucket did not set Retry-After');
        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();

        $this->travel(1)->hour();

        $this->submit()->assertRedirect(self::CHECKOUT_URL);
    }

    public function test_the_callback_is_not_throttled_with_the_initialize_bucket(): void
    {
        $this->exhaustMinute();
        $this->submit()->assertStatus(429);

        $callback = $this->get('/payment/callback?reference=nope');

        $this->assertNotSame(429, $callback->status(), 'the Paystack browser return was throttled');
        $this->assertNull($callback->headers->get('Retry-After'));
    }

    public function test_the_webhook_is_not_throttled_with_the_initialize_bucket(): void
    {
        $this->exhaustMinute();
        $this->submit()->assertStatus(429);

        // Unsigned, so the controller's own answer is 401 — the point is that the
        // throttle never got a say.
        $this->postJson('/paystack/webhook', ['event' => 'charge.success'])->assertStatus(401);
    }

    public function test_the_student_search_throttle_is_a_separate_bucket(): void
    {
        // Exhausting checkout leaves the autocomplete untouched...
        $this->exhaustMinute();
        $this->submit()->assertStatus(429);
        $this->getJson('/pay/alpha/student-search?q=ada')->assertOk();

        // ...and exhausting the autocomplete (60/min) leaves checkout untouched.
        for ($i = 1; $i < 60; $i++) {
            $this->getJson('/s/alpha/payment/student-search?q=zz'.$i)->assertOk();
        }
        $this->getJson('/s/alpha/payment/student-search?q=ada')->assertStatus(429);

        $this->travel(61)->seconds();
        $this->submit()->assertRedirect(self::CHECKOUT_URL);
        $this->getJson('/s/alpha/payment/student-search?q=ada')->assertOk();
    }

    public function test_the_forwarded_client_ip_is_the_key_behind_the_proxy(): void
    {
        // Every request reaches the container from Render's edge with the same
        // REMOTE_ADDR; only X-Forwarded-For tells the parents apart.
        $viaProxy = fn (string $client) => [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => $client,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];

        $this->exhaustMinute(self::CANONICAL, $viaProxy('203.0.113.9'));
        $this->submit(self::CANONICAL, $viaProxy('203.0.113.9'))->assertStatus(429);

        // Another parent behind the same edge is not locked out with them...
        $this->submit(self::CANONICAL, $viaProxy('198.51.100.7'))->assertRedirect(self::CHECKOUT_URL);

        // ...and the proxy's own address was never the key.
        $this->submit(self::CANONICAL, ['REMOTE_ADDR' => '10.0.0.1'])->assertRedirect(self::CHECKOUT_URL);
    }

    public function test_both_initialize_routes_carry_the_named_throttle(): void
    {
        foreach (['public.payment.initialize', 'school.payment.initialize'] as $name) {
            $this->assertContains('throttle:payment-initialize', Route::getRoutes()->getByName($name)->gatherMiddleware(), "{$name} is not throttled");
        }
    }
}
