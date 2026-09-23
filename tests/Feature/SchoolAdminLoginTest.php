<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The admin login identifier is the school's NAME (case-insensitive), not its
 * email. The email is used only by the password-reset flow.
 */
class SchoolAdminLoginTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // the rate limiter must not leak between tests
    }

    public function test_login_page_says_which_identifier_to_use(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('School Name')
            ->assertSee('name="name"', false)
            ->assertSee('not its email address');
    }

    public function test_login_is_by_school_name_case_insensitively(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');

        $this->post('/admin/login', ['name' => 'ALPHA school', 'password' => 'password123'])
            ->assertRedirect('/admin/alpha/dashboard');

        $this->assertSame($school->id, session('school_admin_id'));
    }

    public function test_the_school_email_is_not_a_login_identifier(): void
    {
        $this->makeSchool('Alpha School', 'alpha', ['email' => 'admin@alpha.test']);

        $this->from('/admin/login')
            ->post('/admin/login', ['name' => 'admin@alpha.test', 'password' => 'password123'])
            ->assertRedirect('/admin/login')
            ->assertSessionHas('error', 'Invalid school name or password.');

        $this->assertNull(session('school_admin_id'));
    }

    public function test_a_wrong_password_or_unknown_school_is_rejected_without_a_session(): void
    {
        $this->makeSchool('Alpha School', 'alpha');

        $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'wrong-password'])
            ->assertSessionHas('error');
        $this->assertNull(session('school_admin_id'));

        $this->post('/admin/login', ['name' => 'No Such School', 'password' => 'password123'])
            ->assertSessionHas('error');
        $this->assertNull(session('school_admin_id'));
    }

    public function test_failed_login_attempts_are_throttled_per_school_name_and_ip(): void
    {
        $this->makeSchool('Alpha School', 'alpha');

        // Five wrong guesses are answered normally...
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'wrong-'.$i])
                ->assertRedirect()
                ->assertSessionHas('error', 'Invalid school name or password.');
        }

        // ...the sixth is refused before the password is even checked, right or
        // wrong (L7: back on the login form with a clear message).
        $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123'])
            ->assertRedirect('/admin/login')
            ->assertSessionHas('error');
        $this->assertStringStartsWith('Too many failed sign-in attempts.', session('error'));
        $this->assertNull(session('school_admin_id'));

        // The GET form is not throttled.
        $this->get('/admin/login')->assertOk();
    }

    public function test_a_legitimate_login_within_the_limit_still_works(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');

        for ($i = 1; $i <= 4; $i++) {
            $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'wrong-'.$i])->assertRedirect();
        }

        $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123'])
            ->assertRedirect('/admin/alpha/dashboard');
        $this->assertSame($school->id, session('school_admin_id'));
    }

    public function test_demo_seeder_credentials_log_in_to_the_demo_dashboard(): void
    {
        $this->seed(DemoSeeder::class);

        $this->post('/admin/login', ['name' => DemoSeeder::SCHOOL_NAME, 'password' => DemoSeeder::ADMIN_PASSWORD])
            ->assertRedirect('/admin/'.DemoSeeder::SCHOOL_SLUG.'/dashboard');

        $this->get('/admin/'.DemoSeeder::SCHOOL_SLUG.'/dashboard')->assertOk();
        // The legacy admin URL now bounces the signed-in admin to the canonical page.
        $this->get('/s/'.DemoSeeder::SCHOOL_SLUG.'/dashboard')->assertStatus(301)->assertRedirect('/admin/'.DemoSeeder::SCHOOL_SLUG.'/dashboard');

        // Belt and braces: the seeded email is not accepted as the identifier.
        $this->flushSession();
        $this->post('/admin/login', ['name' => 'admin@demo-academy.test', 'password' => DemoSeeder::ADMIN_PASSWORD])
            ->assertSessionHas('error');
        $this->assertNull(session('school_admin_id'));
    }
}
