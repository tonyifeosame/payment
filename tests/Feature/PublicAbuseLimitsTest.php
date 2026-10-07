<?php

namespace Tests\Feature;

use App\Http\Controllers\PaystackWebhookController;
use App\Mail\SchoolPasswordResetMail;
use App\Models\Transaction;
use App\Support\PaymentCallbackLimiter;
use App\Support\StudentSearchLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L1 student lookup, L2 reset-request lockout guidance, L3 callback and webhook
 * resource limits, L4 CORS.
 */
class PublicAbuseLimitsTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_secret']);
        $school = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
        $this->makeStudent($school, 'A/2026/001', 'Adaeze Okonkwo');
    }

    // ------------------------------------------------------------------ L1

    public function test_student_lookup_allows_ten_a_minute_per_ip_across_both_urls(): void
    {
        for ($i = 0; $i < StudentSearchLimiter::PER_MINUTE; $i++) {
            $url = $i % 2 ? '/pay/alpha/student-search' : '/s/alpha/payment/student-search';
            $this->postJson($url, ['name' => 'Nobody '.$i, 'admission_number' => 'X/'.$i])->assertOk();
        }

        // Even the correct pair is refused once the bucket is spent, on either URL.
        $this->postJson('/pay/alpha/student-search', ['name' => 'Adaeze Okonkwo', 'admission_number' => 'A/2026/001'])->assertStatus(429);
        $this->postJson('/s/alpha/payment/student-search', ['name' => 'Adaeze Okonkwo', 'admission_number' => 'A/2026/001'])->assertStatus(429);

        // Another parent on another address is unaffected, and privacy rules hold.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson('/pay/alpha/student-search', ['name' => 'Adaeze Okonkwo', 'admission_number' => 'A/2026/001'])
            ->assertOk()->assertJsonPath('student.full_name', 'Adaeze Okonkwo')
            ->assertJsonMissingPath('student.admission_number');
    }

    public function test_student_lookup_has_an_hourly_ceiling(): void
    {
        for ($i = 0; $i < StudentSearchLimiter::PER_HOUR; $i++) {
            if ($i > 0 && $i % StudentSearchLimiter::PER_MINUTE === 0) {
                $this->travel(61)->seconds();
            }
            $this->postJson('/pay/alpha/student-search', ['name' => 'N'.$i, 'admission_number' => 'X'.$i])->assertOk();
        }

        $this->travel(61)->seconds();
        $this->postJson('/pay/alpha/student-search', ['name' => 'Adaeze Okonkwo', 'admission_number' => 'A/2026/001'])->assertStatus(429);
    }

    // ------------------------------------------------------------------ L2

    public function test_a_throttled_reset_request_explains_that_the_latest_link_still_works(): void
    {
        for ($i = 0; $i < 5; $i++) {
            if ($i > 0) {
                $this->travel(61)->seconds();
            }
            $this->post('/admin/forgot-password', ['email' => 'alpha@example.test'])->assertRedirect();
        }
        $tokens = [];
        Mail::assertSent(SchoolPasswordResetMail::class, function ($m) use (&$tokens) {
            $tokens[] = basename((string) parse_url($m->resetLink, PHP_URL_PATH));

            return true;
        });

        $this->travel(61)->seconds();
        $this->post('/admin/forgot-password', ['email' => 'alpha@example.test'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('use the most recent one: its link still works', false)
            ->assertSee('name="email"', false);

        // The newest emailed link really does still work.
        $school = \App\Models\School::where('slug', 'alpha')->first();
        $this->assertTrue(Password::broker()->tokenExists($school, end($tokens)));
    }

    // ------------------------------------------------------------------ L3

    public function test_the_payment_callback_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < PaymentCallbackLimiter::PER_MINUTE; $i++) {
            $this->get('/payment/callback?reference=unknown-'.$i)->assertNotFound();
        }

        $this->get('/payment/callback?reference=unknown-x')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])->get('/payment/callback?reference=unknown-y')->assertNotFound();
    }

    public function test_a_legitimate_callback_still_settles(): void
    {
        $school = \App\Models\School::where('slug', 'alpha')->first();
        Transaction::create([
            'school_id' => $school->id, 'reference' => 'ref-cb', 'amount' => 102.5, 'status' => 'pending', 'email' => 'p@example.test',
            'meta_data' => ['quantity' => 1, 'base_amount' => 100, 'markup_amount' => 2.5, 'gross_amount' => 102.5],
        ]);
        Http::fake([
            '*transaction/verify*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 10250, 'currency' => 'NGN', 'reference' => 'ref-cb', 'channel' => 'card']]),
            '*' => Http::response(['status' => false], 400),
        ]);

        $this->get('/payment/callback?reference=ref-cb')->assertRedirect('/s/alpha/payment');
        $this->assertSame('success', Transaction::where('reference', 'ref-cb')->value('status'));
    }

    private function webhook(string $body, ?string $signature, array $server = [])
    {
        return $this->call('POST', '/paystack/webhook', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? '',
        ], $server), $body);
    }

    public function test_an_oversized_webhook_is_refused_before_hashing(): void
    {
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'x', 'pad' => str_repeat('a', PaystackWebhookController::MAX_BODY_BYTES)]]);

        $this->webhook($body, hash_hmac('sha512', $body, 'sk_test_secret'))->assertStatus(413);
    }

    public function test_repeated_bad_signatures_are_cut_off_without_affecting_genuine_deliveries(): void
    {
        for ($i = 0; $i < PaystackWebhookController::MAX_INVALID_PER_MINUTE; $i++) {
            $this->webhook('{"event":"x"}', 'bad', ['REMOTE_ADDR' => '203.0.113.66'])->assertStatus(401);
        }
        $this->webhook('{"event":"x"}', 'bad', ['REMOTE_ADDR' => '203.0.113.66'])->assertStatus(429);

        // Paystack (another address), correctly signed, is untouched — and valid
        // deliveries never count, however many there are.
        $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'no-such-payout']]);
        for ($i = 0; $i < PaystackWebhookController::MAX_INVALID_PER_MINUTE + 5; $i++) {
            $this->webhook($body, hash_hmac('sha512', $body, 'sk_test_secret'), ['REMOTE_ADDR' => '52.31.139.75'])->assertOk();
        }
    }

    // ------------------------------------------------------------------ L4

    public function test_no_cors_headers_are_offered_to_other_origins(): void
    {
        Http::fake(['*/bank*' => Http::response(['status' => true, 'data' => [['name' => 'GTB', 'code' => '058']]])]);

        $preflight = $this->call('OPTIONS', '/api/banks', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Origin'));

        $get = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/banks');
        $get->assertOk(); // the request is served (same-origin use is unchanged)...
        $this->assertNull($get->headers->get('Access-Control-Allow-Origin')); // ...but no other site may read it

        $this->assertNull($this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/api/resolve-account?account_number=0123456789&bank_code=058')
            ->headers->get('Access-Control-Allow-Origin'));
    }
}
