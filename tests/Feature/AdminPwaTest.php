<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The installable "FEYRA Admin" app: an admin-only manifest and entry URL, no
 * service worker, and nothing PWA-related on public pages.
 */
class AdminPwaTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
    }

    public function test_manifest_is_served_as_valid_admin_only_json(): void
    {
        $response = $this->get('/admin/manifest.webmanifest')->assertOk();
        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));

        $manifest = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('FEYRA Admin', $manifest['name']);
        $this->assertSame('FEYRA Admin', $manifest['short_name']);
        $this->assertSame("Manage your school's payments, students, fees and payouts.", $manifest['description']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('en', $manifest['lang']);
        $this->assertSame('/admin/', $manifest['start_url']);
        $this->assertSame('/admin', $manifest['id']);
        $this->assertSame('/admin/', $manifest['scope']);
        $this->assertSame('#FFFFFF', $manifest['theme_color']);
        $this->assertSame('#F7F7F8', $manifest['background_color']); // FEYRA fog
        $this->assertStringNotContainsString('/s/', json_encode($manifest)); // no legacy dependency

        // Web App Manifest scope rule, exactly as Chrome applies it: a same-origin URL is
        // within scope when its path string starts with the scope path. Chrome ignores a
        // scope the start_url is not inside of, so start_url must pass the same test.
        $inScope = fn (string $url) => str_starts_with((string) parse_url($url, PHP_URL_PATH), $manifest['scope']);
        $this->assertTrue($inScope($manifest['start_url']), 'start_url must be inside scope or Chrome discards the scope');
        foreach (['/admin/', '/admin/login', '/admin/demo-academy/dashboard', '/admin/demo-academy/students', '/admin/demo-academy/transactions',
            '/admin/demo-academy/payouts', '/admin/demo-academy/settings', '/admin/manifest.webmanifest', '/admin/forgot-password'] as $url) {
            $this->assertTrue($inScope($url), "{$url} must be inside the app scope");
        }
        foreach (['/', '/pay/demo-academy', '/pay/demo-academy/student-search', '/pay/demo-academy/initialize', '/payment/receipt/1',
            '/payment/receipt/1/download', '/payment/callback', '/registration/create', '/contact', '/s/demo-academy/payment',
            '/s/demo-academy/dashboard', '/s/_app', '/administrator', '/admin-tools/x', '/api/banks'] as $url) {
            $this->assertFalse($inScope($url), "{$url} must not be inside the app scope");
        }

        // Icons: the sizes Chrome needs, all real files derived from the mark, one maskable.
        $this->assertCount(3, $manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
            $this->assertSame('image/png', $icon['type']);
            [$w, $h] = getimagesize(public_path($icon['src']));
            $this->assertSame("{$w}x{$h}", $icon['sizes']);
        }
        $this->assertSame(['any', 'any', 'maskable'], array_column($manifest['icons'], 'purpose'));
        $this->assertContains('512x512', array_column($manifest['icons'], 'sizes'));
        $this->assertContains('192x192', array_column($manifest['icons'], 'sizes'));
    }

    public function test_admin_layout_advertises_the_manifest_and_public_layouts_do_not(): void
    {
        $admin = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard')->assertOk();
        $admin->assertSee('rel="manifest" href="http://localhost/admin/manifest.webmanifest"', false)
            ->assertSee('<meta name="theme-color" content="#FFFFFF">', false)
            ->assertSee('<meta name="application-name" content="FEYRA Admin">', false)
            ->assertSee('id="adminInstallApp"', false)
            ->assertSee('beforeinstallprompt', false)
            // No service worker anywhere: nothing is cached, offline is the browser's own error.
            ->assertDontSee('serviceWorker', false)
            ->assertDontSee('navigator.serviceWorker', false);
        $this->assertSame(1, substr_count($admin->getContent(), 'rel="manifest"'));

        foreach (['/', '/s/alpha/payment', '/contact', '/registration/create', '/admin/login'] as $public) {
            $page = $this->get($public)->assertOk();
            $page->assertDontSee('manifest.webmanifest', false)->assertDontSee('rel="manifest"', false)
                ->assertDontSee('serviceWorker', false)->assertDontSee('adminInstallApp', false);
        }

        // Receipts are public too.
        $t = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-1']);
        $this->withSession(['last_transaction_id' => $t->id])->get("/payment/receipt/{$t->id}")->assertOk()
            ->assertDontSee('manifest.webmanifest', false)->assertDontSee('serviceWorker', false);
    }

    public function test_start_url_opens_login_or_the_signed_in_schools_dashboard(): void
    {
        // Guest: the login page, without touching authentication. The manifest's
        // start_url (/admin/) is the same entry point.
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/')->assertRedirect('/admin/login');
        $this->assertNull(session('school_admin_id'));

        // Signed in: that school's dashboard, never another school's — and only the
        // session decides; a slug in the query string is ignored.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin')->assertRedirect('/admin/alpha/dashboard');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/')->assertRedirect('/admin/alpha/dashboard');
        $beta = $this->makeSchool('Beta School', 'beta');
        $this->actingAsSchoolAdmin($beta)->get('/admin')->assertRedirect('/admin/beta/dashboard');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin?school=beta')->assertRedirect('/admin/alpha/dashboard');
        $this->flushSession();
        $this->get('/admin?school=alpha')->assertRedirect('/admin/login');

        // A session for a deleted school is cleared and sent to login (same as EnsureSchoolAdmin).
        $this->withSession(['school_admin_id' => 999999])->get('/admin')->assertRedirect('/admin/login');
        $this->assertNull(session('school_admin_id'));
        $this->post('/admin')->assertStatus(405);

        // /s/_app is kept only for compatibility with the previous manifest: same behaviour.
        $this->get('/s/_app')->assertRedirect('/admin/login');
        $this->actingAsSchoolAdmin($this->alpha)->get('/s/_app')->assertRedirect('/admin/alpha/dashboard');
        $this->assertSame('app', \Illuminate\Support\Str::slug('_app'));
    }

    public function test_existing_authentication_and_session_expiry_are_unchanged(): void
    {
        // Login still works by school name and lands on the dashboard.
        $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123'])->assertRedirect('/admin/alpha/dashboard');
        $this->assertSame($this->alpha->id, session('school_admin_id'));
        $this->get('/admin')->assertRedirect('/admin/alpha/dashboard');

        // Logout clears the session; the app entry and admin pages then go to login.
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertNull(session('school_admin_id'));
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
        $this->get('/s/alpha/dashboard')->assertRedirect('/admin/login');

        // The manifest and icons need no session and reveal nothing tenant-specific.
        $manifest = $this->get('/admin/manifest.webmanifest')->assertOk()->getContent();
        $this->assertStringNotContainsString('alpha', $manifest);
        $this->assertStringNotContainsString('Alpha School', $manifest);
    }
}
