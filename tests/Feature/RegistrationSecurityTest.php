<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression tests for F2 — registration.
 *
 * Self-service registration stays open (that is the product's intent), but a freshly
 * registered school must be confined to its own tenant and must be rate limited.
 */
class RegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // reset the rate limiter between tests
        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_fake']);
        Http::fake([
            '*bank/resolve*' => Http::response([
                'status' => true,
                'data' => ['account_name' => 'Resolved Name', 'account_number' => '0123456789'],
            ], 200),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New School',
            'email' => 'new@example.test',
            'account_number' => '0123456789',
            'bank' => 'GTB',
            'bank_code' => '058',
            'address' => '1 Test Road',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_registration_still_works_and_logs_the_new_admin_in(): void
    {
        $response = $this->post('/registration', $this->payload());

        $response->assertRedirect('/s/new-school/categories');
        $this->assertNotNull(session('school_admin_id'));
        $this->assertDatabaseHas('schools', ['slug' => 'new-school']);
    }

    public function test_a_newly_registered_admin_cannot_reach_an_existing_school(): void
    {
        $victim = School::create([
            'name' => 'Victim School',
            'slug' => 'victim',
            'email' => 'victim@example.test',
            'admin_password' => Hash::make('password123'),
        ]);

        $victimCategory = Category::create(['name' => 'VictimCategory', 'school_id' => $victim->id]);

        Transaction::create([
            'school_id' => $victim->id,
            'reference' => 'victim-txn',
            'amount' => 5000,
            'status' => 'success',
            'email' => 'victimparent@private.test',
            'name' => 'VictimPayerName',
            'meta_data' => ['base_amount' => 5000],
        ]);

        // Attacker registers and is auto-logged-in.
        $this->post('/registration', $this->payload(['name' => 'Attacker School', 'email' => 'attacker@example.test']));
        $this->assertNotNull(session('school_admin_id'));

        // ...and is confined to their own tenant.
        $this->get('/s/victim/categories')->assertNotFound();
        $this->get('/s/victim/transactions')->assertNotFound();
        $this->get("/s/attacker-school/categories/{$victimCategory->id}/edit")->assertNotFound();
        $this->delete("/s/attacker-school/categories/{$victimCategory->id}")->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $victimCategory->id, 'name' => 'VictimCategory']);

        // Their own transactions page must not contain the victim's payers.
        $this->get('/s/attacker-school/transactions')
            ->assertOk()
            ->assertDontSee('VictimPayerName')
            ->assertDontSee('victimparent@private.test');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->flushSession();
            $response = $this->post('/registration', $this->payload([
                'name' => "School {$i}",
                'email' => "school{$i}@example.test",
            ]));
            $this->assertNotEquals(429, $response->status(), "request {$i} was throttled too early");
        }

        $this->flushSession();
        $this->post('/registration', $this->payload([
            'name' => 'School 11',
            'email' => 'school11@example.test',
        ]))->assertStatus(429);
    }

    public function test_registration_rate_limit_does_not_apply_to_the_form_page(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->get('/registration/create')->assertOk();
        }
    }
}
