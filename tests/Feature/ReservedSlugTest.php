<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L10 — a school's slug is its route key, so `/admin/{slug}/…` competes for the
 * same URL space as the literal admin routes registered before it.
 *
 * Exactly one of them shadows a school today. `admin/reset-password/{token}` is
 * registered before the `admin/{school:slug}` prefix and its wildcard swallows
 * the segment after it, so for a school slugged `reset-password` EVERY page —
 * dashboard, settings, transactions — resolves to the password-reset form
 * instead. `login`, `logout`, `forgot-password` and `manifest` are fixed
 * two-segment paths and do not collide, but they are reserved anyway: what made
 * reset-password dangerous was a wildcard child being added to a segment that
 * had looked safe, and the guard test at the bottom is what keeps that honest.
 */
class ReservedSlugTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        config(['services.paystack.secret_key' => 'sk_test_fake']);
    }

    private function register(string $name): \Illuminate\Testing\TestResponse
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'RESOLVED LTD', 'account_number' => '0123456789'],
        ], 200)]);

        return $this->post('/registration', [
            'name' => $name,
            'email' => \Illuminate\Support\Str::slug($name).'-'.uniqid().'@example.test',
            'account_number' => '0123456789',
            'bank' => 'Guaranty Trust Bank',
            'bank_code' => '058',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ]);
    }

    /**
     * The school just registered under this name.
     *
     * Not School::sole(): an early migration seeds a "Default School" row, so the
     * table is never empty.
     */
    private function registered(string $name): School
    {
        return School::where('name', $name)->sole();
    }

    // ------------------------------------------------------ reserved slugs

    public function test_a_school_named_after_a_reserved_segment_gets_a_suffixed_slug(): void
    {
        $this->register('Reset Password')->assertRedirect('/admin/reset-password-1/dashboard');

        $school = $this->registered('Reset Password');
        $this->assertSame('reset-password-1', $school->slug);
        $this->assertNotContains($school->slug, School::RESERVED_SLUGS);
    }

    public function test_every_reserved_segment_is_refused_as_a_slug(): void
    {
        foreach (School::RESERVED_SLUGS as $i => $reserved) {
            $name = ucwords(str_replace('-', ' ', $reserved));

            $slug = School::availableSlugFor($name);

            $this->assertSame($reserved.'-1', $slug, "{$reserved} was not reserved");
            $this->assertTrue(School::slugIsUnavailable($reserved));
        }
    }

    public function test_the_shadowed_slug_can_no_longer_be_minted_so_the_reset_route_keeps_resolving(): void
    {
        $this->register('Reset Password')->assertRedirect();
        $school = $this->registered('Reset Password');

        // The school's own pages resolve to the school, not to the reset form.
        $this->actingAsSchoolAdmin($school)
            ->get('/admin/'.$school->slug.'/dashboard')
            ->assertOk()
            ->assertSee('Reset Password');   // the school's name

        // And the password-reset route still resolves to the reset form.
        $this->flushSession();
        $this->get('/admin/reset-password/some-token?email=x@example.test')
            ->assertOk()
            ->assertSee('Reset');
    }

    // -------------------------------------------------------- normal slugs

    public function test_an_ordinary_name_still_gets_the_obvious_slug(): void
    {
        $this->register('Springfield Academy')->assertRedirect('/admin/springfield-academy/dashboard');

        $this->assertSame('springfield-academy', $this->registered('Springfield Academy')->slug);
    }

    public function test_a_name_merely_containing_a_reserved_word_is_untouched(): void
    {
        // Reserving costs a school nothing unless its WHOLE name slugifies to the
        // reserved word.
        $this->assertSame('login-academy', School::availableSlugFor('Login Academy'));
        $this->assertSame('the-admin-school', School::availableSlugFor('The Admin School'));
        $this->assertSame('password-reset-college', School::availableSlugFor('Password Reset College'));
    }

    // ---------------------------------------------------- collision suffixes

    public function test_the_existing_collision_behaviour_is_preserved(): void
    {
        $this->makeSchool('Springfield Academy', 'springfield-academy');

        $this->assertSame('springfield-academy-1', School::availableSlugFor('Springfield Academy'));

        $this->makeSchool('Springfield Academy Two', 'springfield-academy-1');
        $this->assertSame('springfield-academy-2', School::availableSlugFor('Springfield Academy'));
    }

    public function test_a_reserved_slug_and_a_taken_suffix_walk_on_together(): void
    {
        // reserved takes -1; if a school already holds -1, the next is -2.
        $this->makeSchool('Something Else', 'reset-password-1');

        $this->assertSame('reset-password-2', School::availableSlugFor('Reset Password'));
    }

    public function test_registration_still_suffixes_a_duplicate_name_slug(): void
    {
        // Names are unique case-insensitively (H4), so a duplicate SLUG is reached
        // through a different name that slugifies the same way.
        School::create([
            'name' => 'Springfield-Academy',
            'slug' => 'springfield-academy',
            'email' => 'existing@example.test',
            'admin_password' => Hash::make('password123'),
        ]);

        $this->register('Springfield Academy')->assertRedirect('/admin/springfield-academy-1/dashboard');
    }

    // -------------------------------------------------- tenant isolation

    public function test_a_suffixed_school_is_still_properly_isolated(): void
    {
        $this->register('Reset Password')->assertRedirect();
        $reserved = $this->registered('Reset Password');

        $other = $this->makeSchool('Beta School', 'beta');

        // Beta's admin cannot reach the suffixed school's pages...
        $this->flushSession();
        $this->actingAsSchoolAdmin($other)
            ->get('/admin/'.$reserved->slug.'/dashboard')
            ->assertNotFound();

        // ...and the suffixed school's admin cannot reach beta's.
        $this->flushSession();
        $this->actingAsSchoolAdmin($reserved)
            ->get('/admin/beta/dashboard')
            ->assertNotFound();
    }

    // ------------------------------------------------------- the guard test

    public function test_the_reserved_list_covers_every_literal_admin_segment(): void
    {
        // If a literal /admin/<segment>/… route is ever added without reserving
        // <segment>, this fails — which is exactly how reset-password slipped in.
        $segments = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'admin/'))
            ->map(fn (string $uri) => explode('/', $uri)[1] ?? null)
            ->filter()
            ->reject(fn (string $segment) => str_starts_with($segment, '{'))
            // Only segments a generated slug could actually equal. Str::slug never
            // produces a dot, so `manifest.webmanifest` can never be a slug and
            // cannot be shadowed by one.
            ->filter(fn (string $segment) => \Illuminate\Support\Str::slug($segment) === $segment)
            ->unique()
            ->values();

        $this->assertNotEmpty($segments, 'no literal admin segments found — the query is wrong');

        foreach ($segments as $segment) {
            $this->assertContains(
                $segment,
                School::RESERVED_SLUGS,
                "the literal /admin/{$segment} route is not in School::RESERVED_SLUGS"
            );
        }
    }
}
