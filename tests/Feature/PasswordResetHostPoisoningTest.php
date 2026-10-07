<?php

namespace Tests\Feature;

use App\Mail\SchoolPasswordResetMail;
use App\Models\School;
use App\Providers\AppServiceProvider;
use App\Support\AppUrl;
use App\Support\SchoolSession;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * H1: password-reset links (and every other generated URL) were built from the
 * request's Host / X-Forwarded-Host, so a forged header delivered a live reset
 * token to the forger's domain. The link now comes from APP_URL; the URL root is
 * forced to APP_URL in production (the provider that does it now actually loads);
 * and X-Forwarded-Host is no longer a trusted proxy header.
 */
class PasswordResetHostPoisoningTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const APP_URL = 'https://feyra.example.test';

    /** Render's edge: TLS terminated upstream, real client in X-Forwarded-For. */
    private const PROXY = [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ];

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        config(['app.url' => self::APP_URL]);

        $this->school = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
    }

    /** Run as production: env flag, the provider's boot, and CSRF (which unit tests otherwise skip). */
    private function asProduction(): void
    {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function requestReset(array $server = [], string $uri = '/admin/forgot-password'): string
    {
        $this->withServerVariables($server)->post($uri, ['email' => 'alpha@example.test']);

        $link = null;
        Mail::assertSent(SchoolPasswordResetMail::class, function (SchoolPasswordResetMail $mail) use (&$link) {
            $link = $mail->resetLink;

            return true;
        });

        return (string) $link;
    }

    private function assertTrustedOrigin(string $link, string $expectedScheme = 'https'): void
    {
        $this->assertSame('feyra.example.test', parse_url($link, PHP_URL_HOST), 'the reset link left the configured host');
        $this->assertSame($expectedScheme, parse_url($link, PHP_URL_SCHEME));
        $this->assertStringStartsWith('/admin/reset-password/', (string) parse_url($link, PHP_URL_PATH));
        $this->assertSame('email=alpha%40example.test', parse_url($link, PHP_URL_QUERY));
    }

    public function test_a_normal_reset_request_links_to_the_configured_app_url(): void
    {
        // The test client's own host is localhost; the link must not be.
        $this->assertTrustedOrigin($this->requestReset());
    }

    public function test_a_forged_host_header_cannot_change_the_link_host(): void
    {
        $link = $this->requestReset(['HTTP_HOST' => 'attacker.example'], 'http://attacker.example/admin/forgot-password');

        $this->assertTrustedOrigin($link);
        $this->assertStringNotContainsString('attacker.example', $link);
    }

    public function test_a_forged_x_forwarded_host_cannot_change_the_link_host(): void
    {
        $link = $this->requestReset(self::PROXY + ['HTTP_X_FORWARDED_HOST' => 'attacker.example']);

        $this->assertTrustedOrigin($link);
        $this->assertStringNotContainsString('attacker.example', $link);
    }

    public function test_both_forged_headers_are_ignored_in_production(): void
    {
        $this->asProduction();

        $link = $this->requestReset(
            self::PROXY + ['HTTP_HOST' => 'attacker.example', 'HTTP_X_FORWARDED_HOST' => 'attacker2.example'],
            'http://attacker.example/admin/forgot-password'
        );

        $this->assertTrustedOrigin($link);
    }

    public function test_an_http_app_url_still_yields_an_https_link_in_production(): void
    {
        config(['app.url' => 'http://feyra.example.test']);
        $this->asProduction();

        $this->assertTrustedOrigin($this->requestReset(self::PROXY));
    }

    public function test_outside_production_the_configured_scheme_is_kept(): void
    {
        config(['app.url' => 'http://localhost:8000/']);

        $this->assertSame('http://localhost:8000', AppUrl::root());
        $this->assertSame('http://localhost:8000/admin/x', AppUrl::to('/admin/x'));
    }

    public function test_x_forwarded_host_is_not_a_trusted_proxy_header(): void
    {
        $request = Request::create('http://laravel-app.onrender.test/', 'GET', [], [], [], self::PROXY + [
            'HTTP_HOST' => 'laravel-app.onrender.test',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
        ]);
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        $this->assertTrue($request->isFromTrustedProxy());
        $this->assertSame('laravel-app.onrender.test', $request->getHost(), 'X-Forwarded-Host was honoured');
        // The headers the deployment does rely on are still honoured.
        $this->assertTrue($request->secure());
        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_the_app_service_provider_is_actually_loaded(): void
    {
        $this->assertFileExists(base_path('app/Providers/AppServiceProvider.php'));
        $this->assertDirectoryDoesNotExist(base_path('Providers'));
        $this->assertNotEmpty($this->app->getProviders(AppServiceProvider::class), 'AppServiceProvider is not registered');
    }

    public function test_in_production_every_generated_url_uses_app_url_not_the_request_host(): void
    {
        $this->asProduction();

        $this->withServerVariables(self::PROXY + ['HTTP_HOST' => 'attacker.example'])
            ->get('http://attacker.example/');

        foreach ([route('public.payment', ['school' => 'alpha']), asset('images/feyra-mark.png'), url('/admin/login'), URL::signedRoute('payment.receipt', ['transaction' => 1])] as $url) {
            $this->assertSame('feyra.example.test', parse_url($url, PHP_URL_HOST), $url);
            $this->assertSame('https', parse_url($url, PHP_URL_SCHEME), $url);
        }
    }

    public function test_the_emailed_link_completes_a_real_reset(): void
    {
        $oldSession = SchoolSession::payloadFor($this->school);
        $link = $this->requestReset();

        // The path and query of the emailed link open the form on this app.
        $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
        $this->get($path)->assertOk();

        $token = basename((string) parse_url($link, PHP_URL_PATH));
        $this->post('/admin/reset-password', [
            'token' => $token,
            'email' => 'alpha@example.test',
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect(route('admin.login'));

        $this->assertTrue(Hash::check('a-brand-new-passphrase', $this->school->fresh()->admin_password));

        // A session authenticated under the old password no longer works.
        $this->withSession($oldSession)->get('/admin/alpha/dashboard')->assertRedirect(route('admin.login'));
    }
}
