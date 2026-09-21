<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * URL migration, stages 2-3: every authenticated admin route lives canonically at
 * /admin/{school}/... under its existing `school.*` name, with a `legacy.school.*`
 * twin at /s/{school}/... that carries the same action, middleware and scoped
 * bindings. Since stage 3 a permitted legacy GET is 301-redirected to the canonical
 * URL (after authentication, school check and record binding), while legacy write
 * methods still run their unchanged actions.
 */
class AdminNamespaceTest extends TestCase
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

    public function test_every_canonical_admin_route_has_an_identical_legacy_twin(): void
    {
        $routes = Route::getRoutes();
        $canonical = collect($routes->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin/{school}/'));

        $this->assertCount(41, $canonical, 'all authenticated admin routes are registered canonically');

        $expected = [
            'dashboard', 'students', 'students/create', 'students/{student}', 'students/{student}/edit',
            'students/classes', 'students/classes/{classLevel}', 'students/classes/{classLevel}/move', 'students/classes/assign',
            'students/promotion', 'students/promotion/review', 'sessions', 'terms/{academicTerm}/current',
            'payouts', 'payouts/{payout}', 'settings', 'settings/bank', 'settings/password', 'share', 'share/qr.svg',
            'categories', 'categories/{category}', 'categories/{category}/edit',
            'subcategories', 'subcategories/create', 'subcategories/{subcategory}', 'subcategories/{subcategory}/edit',
            'transactions', 'transactions/export', 'transactions/{transaction}',
        ];
        $paths = $canonical->map(fn ($r) => substr($r->uri(), strlen('admin/{school}/')))->unique()->values()->all();
        $this->assertEqualsCanonicalizing($expected, $paths);

        foreach ($canonical as $route) {
            $name = $route->getName();
            $this->assertStringStartsWith('school.', $name, "canonical route {$route->uri()} keeps its school.* name");
            $legacy = $routes->getByName('legacy.'.$name);
            $this->assertNotNull($legacy, "legacy twin for {$name}");
            $this->assertSame('s/{school}/'.substr($route->uri(), strlen('admin/{school}/')), $legacy->uri());
            $this->assertSame($route->methods(), $legacy->methods());
            $this->assertSame($route->getActionName(), $legacy->getActionName());
            // Legacy = canonical middleware + the redirect, which runs after the tenant check.
            $legacyMiddleware = $legacy->gatherMiddleware();
            $this->assertEqualsCanonicalizing(array_merge($route->gatherMiddleware(), [\App\Http\Middleware\RedirectLegacyAdminUrls::class]), $legacyMiddleware);
            $this->assertGreaterThan(array_search(\App\Http\Middleware\EnsureSchoolAdmin::class, $legacyMiddleware, true), array_search(\App\Http\Middleware\RedirectLegacyAdminUrls::class, $legacyMiddleware, true));
            $this->assertContains(\App\Http\Middleware\EnsureSchoolAdmin::class, $route->gatherMiddleware());
            $this->assertNotContains(\App\Http\Middleware\RedirectLegacyAdminUrls::class, $route->gatherMiddleware());
            $this->assertSame($route->bindingFields(), $legacy->bindingFields());
            $this->assertTrue($route->enforcesScopedBindings());
            $this->assertTrue($legacy->enforcesScopedBindings());
        }

        // route() now generates canonical URLs everywhere; legacy names still resolve.
        $this->assertSame('http://localhost/admin/alpha/students', route('school.students.index', ['school' => 'alpha']));
        $this->assertSame('http://localhost/s/alpha/students', route('legacy.school.students.index', ['school' => 'alpha']));

        // Public routes are untouched by the admin migration.
        $this->assertSame('pay/{school}', $routes->getByName('public.payment')->uri());
        $this->assertSame('s/{school}/payment', $routes->getByName('school.payment.index')->uri());
        $this->assertSame('s/{school}/logo', $routes->getByName('school.logo')->uri());
        $this->assertSame('admin/login', $routes->getByName('admin.login')->uri());
    }

    public function test_admin_entry_point_goes_to_login_or_the_signed_in_schools_canonical_dashboard(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->assertNull(session('school_admin_id'));

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin')->assertRedirect('/admin/alpha/dashboard');
        $this->actingAsSchoolAdmin($this->beta)->get('/admin')->assertRedirect('/admin/beta/dashboard');
        $this->withSession(['school_admin_id' => 999999])->get('/admin')->assertRedirect('/admin/login');

        // Login → /admin → own dashboard, end to end.
        $this->flushSession();
        $this->post('/admin/login', ['name' => 'Beta School', 'password' => 'password123'])->assertRedirect('/admin/beta/dashboard');
        $this->get('/admin')->assertRedirect('/admin/beta/dashboard');
        $this->get('/admin/beta/dashboard')->assertOk()->assertSee('Beta School');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->post('/admin')->assertStatus(405);
    }

    public function test_legacy_admin_urls_redirect_permanently_to_the_canonical_page(): void
    {
        $student = $this->makeStudent($this->alpha, 'A/1', 'Ada Okonkwo');

        foreach ([
            '/s/alpha/dashboard', '/s/alpha/students', "/s/alpha/students/{$student->id}", "/s/alpha/students/{$student->id}/edit",
            '/s/alpha/students/create', '/s/alpha/students/classes', '/s/alpha/students/promotion', '/s/alpha/sessions',
            '/s/alpha/categories', '/s/alpha/subcategories', '/s/alpha/subcategories/create', '/s/alpha/transactions',
            '/s/alpha/payouts', '/s/alpha/settings', '/s/alpha/share', '/s/alpha/share/qr.svg',
        ] as $legacy) {
            $canonical = str_replace('/s/alpha/', '/admin/alpha/', $legacy);
            $this->actingAsSchoolAdmin($this->alpha)->get($legacy)->assertStatus(301)->assertRedirect($canonical);
            $this->actingAsSchoolAdmin($this->alpha)->get($canonical)->assertOk();
        }

        // The query string travels with the redirect (filters, pages, exports).
        // (Laravel normalises the query string to key order.)
        $this->actingAsSchoolAdmin($this->alpha)->get('/s/alpha/transactions?status=all&page=2')->assertStatus(301)->assertRedirect('/admin/alpha/transactions?page=2&status=all');
        $this->actingAsSchoolAdmin($this->alpha)->get('/s/alpha/transactions/export?status=all')->assertStatus(301)->assertRedirect('/admin/alpha/transactions/export?status=all');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export?status=all')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        // A legacy write endpoint is not bounced: it runs the unchanged action, whose own
        // redirect lands on the canonical page.
        $this->actingAsSchoolAdmin($this->alpha)->post('/s/alpha/categories', ['name' => 'Legacy Posted'])->assertStatus(302)->assertRedirect('/admin/alpha/categories');
        $this->assertDatabaseHas('categories', ['school_id' => $this->alpha->id, 'name' => 'Legacy Posted']);
        $this->actingAsSchoolAdmin($this->alpha)->put("/s/alpha/students/{$student->id}", ['full_name' => 'Ada Renamed', 'admission_number' => 'A/1', 'class_name' => 'JSS 1', 'status' => 'active'])
            ->assertStatus(302)->assertRedirect('/admin/alpha/students');
        $this->assertDatabaseHas('students', ['id' => $student->id, 'full_name' => 'Ada Renamed']);
        $category = \App\Models\Category::where('name', 'Legacy Posted')->first();
        $this->actingAsSchoolAdmin($this->alpha)->delete("/s/alpha/categories/{$category->id}")->assertStatus(302)->assertRedirect('/admin/alpha/categories');
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        // Validation failures on a legacy POST go back to the referring (canonical) page as before.
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/categories')->post('/s/alpha/categories', ['name' => ''])->assertRedirect('/admin/alpha/categories')->assertSessionHasErrors('name');
    }

    public function test_tenant_isolation_and_guest_protection_hold_on_both_prefixes(): void
    {
        $betaStudent = $this->makeStudent($this->beta, 'B/1', 'Beta Only');

        foreach (['admin', 's'] as $prefix) {
            // Guests: sent to login (never redirected to a canonical page first).
            $this->flushSession();
            $this->get("/{$prefix}/alpha/dashboard")->assertStatus(302)->assertRedirect('/admin/login');
            $this->post("/{$prefix}/alpha/students", ['full_name' => 'X', 'admission_number' => 'X/1', 'class_name' => 'C'])->assertRedirect('/admin/login');
            // Wrong school in the URL.
            $this->actingAsSchoolAdmin($this->alpha)->get("/{$prefix}/beta/dashboard")->assertNotFound();
            $this->actingAsSchoolAdmin($this->alpha)->get("/{$prefix}/beta/students")->assertNotFound();
            $this->actingAsSchoolAdmin($this->alpha)->put("/{$prefix}/beta/settings", ['name' => 'Hijacked', 'email' => 'x@example.test'])->assertNotFound();
            // Scoped binding: another school's record id under the right school 404s.
            $this->actingAsSchoolAdmin($this->alpha)->get("/{$prefix}/alpha/students/{$betaStudent->id}")->assertNotFound();
            $this->actingAsSchoolAdmin($this->alpha)->put("/{$prefix}/alpha/students/{$betaStudent->id}", ['full_name' => 'Hijacked', 'admission_number' => 'B/1', 'class_name' => 'C', 'status' => 'active'])->assertNotFound();
            $this->actingAsSchoolAdmin($this->alpha)->get("/{$prefix}/nope/dashboard")->assertNotFound();
        }
        $this->assertDatabaseHas('students', ['id' => $betaStudent->id, 'full_name' => 'Beta Only']);
        $this->assertDatabaseHas('schools', ['id' => $this->beta->id, 'name' => 'Beta School']);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_public_payment_pages_are_unaffected(): void
    {
        $this->makeFee($this->alpha, 'Fees', 'Tuition', 1000);
        foreach (['/pay/alpha', '/s/alpha/payment'] as $url) {
            $this->get($url)->assertOk()->assertSee('Tuition')->assertDontSee('/admin/alpha/', false)->assertDontSee('manifest.webmanifest', false);
        }
        $this->get('/admin/alpha/payment')->assertNotFound();
        $this->get('/admin/alpha')->assertNotFound();
        $this->getJson('/s/alpha/payment/student-search?q=ab')->assertOk();
        $this->getJson('/pay/alpha/student-search?q=ab')->assertOk();
        // The legacy admin redirect never touches public legacy routes.
        $this->get('/s/alpha/payment')->assertOk();
        $this->get('/s/alpha/logo')->assertNotFound(); // no logo uploaded: the route's own 404, not a redirect
        $this->post('/s/alpha/payment/initialize', [])->assertStatus(302)->assertSessionHasErrors();
    }

    private function title(string $html): string
    {
        preg_match('/<title>(.*?)<\/title>/s', $html, $m);

        return trim($m[1] ?? '');
    }
}
