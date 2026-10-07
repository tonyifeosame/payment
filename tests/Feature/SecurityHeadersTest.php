<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M4: no response carried a CSP, frame protection, nosniff, a referrer policy or
 * HSTS. Every response now does, and the CSP is nonce-based: every inline script
 * the application renders must carry this request's nonce, and no inline event
 * handler may exist (a nonce cannot cover one).
 */
class SecurityHeadersTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private function csp(TestResponse $response): array
    {
        $directives = [];
        foreach (explode(';', (string) $response->headers->get('Content-Security-Policy')) as $part) {
            $tokens = preg_split('/\s+/', trim($part));
            if ($tokens[0] !== '') {
                $directives[array_shift($tokens)] = $tokens;
            }
        }

        return $directives;
    }

    private function assertBaseline(TestResponse $response): void
    {
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertNotEmpty($response->headers->get('Permissions-Policy'));
        $this->assertSame(["'none'"], $this->csp($response)['frame-ancestors']);
    }

    /** Every inline <script> in the body carries exactly the header's nonce. */
    private function assertScriptsCarryTheNonce(TestResponse $response): void
    {
        $csp = $this->csp($response);
        $nonceSource = collect($csp['script-src'])->first(fn ($s) => str_starts_with($s, "'nonce-"));
        $this->assertNotNull($nonceSource, 'script-src has no nonce');
        $nonce = substr($nonceSource, 7, -1);

        preg_match_all('/<script\b([^>]*)>/i', $response->getContent(), $m);
        foreach ($m[1] as $attributes) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $attributes, 'an inline script would be blocked by the CSP');
        }
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $response->getContent(), 'inline event handler found');
    }

    public function test_public_admin_and_payment_pages_carry_the_headers_and_a_working_nonce(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');
        $this->makeFee($school, 'School fees', 'Tuition', 1000);

        $pages = [
            $this->get('/'),
            $this->get('/contact'),
            $this->get('/admin/login'),
            $this->get('/registration/create'),
            $this->get('/admin/forgot-password'),
            $this->get('/pay/alpha'),
            $this->get('/s/alpha/payment'),
            $this->actingAsSchoolAdmin($school)->get('/admin/alpha/dashboard'),
            $this->actingAsSchoolAdmin($school)->get('/admin/alpha/settings'),
            $this->actingAsSchoolAdmin($school)->get('/admin/alpha/share'),
            $this->actingAsSchoolAdmin($school)->get('/admin/alpha/students/promotion'),
        ];

        foreach ($pages as $response) {
            $response->assertOk();
            $this->assertBaseline($response);
            $this->assertScriptsCarryTheNonce($response);
        }
    }

    public function test_the_nonce_changes_on_every_request(): void
    {
        $a = $this->csp($this->get('/admin/login'))['script-src'];
        $b = $this->csp($this->get('/admin/login'))['script-src'];

        $this->assertNotSame($a, $b);
    }

    public function test_the_policy_allows_exactly_what_the_app_uses(): void
    {
        $csp = $this->csp($this->get('/'));

        $this->assertSame(["'self'"], $csp['default-src']);
        $this->assertNotContains("'unsafe-inline'", $csp['script-src']);
        $this->assertNotContains("'unsafe-eval'", $csp['script-src']);
        $this->assertSame(["'self'"], $csp['connect-src']);
        $this->assertSame(["'self'"], $csp['font-src']);
        $this->assertSame(["'none'"], $csp['object-src']);
        $this->assertSame(["'self'"], $csp['base-uri']);
        // Paystack's hosted checkout is reached by redirect after our form posts.
        $this->assertSame(["'self'", SecurityHeaders::PAYSTACK_CHECKOUT], $csp['form-action']);
    }

    public function test_paystack_checkout_redirect_is_permitted_by_form_action(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*/transaction/initialize' => \Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123']])]);
        config(['services.paystack.secret_key' => 'sk_test_fake']);
        $school = $this->makeSchool('Alpha School', 'alpha');
        $fee = $this->makeFee($school, 'School fees', 'Tuition', 1000);

        $response = $this->post('/pay/alpha/initialize', [
            'email' => 'parent@example.test', 'category_id' => $fee->category_id, 'subcategory_id' => $fee->id, 'quantity' => 1,
        ]);

        $response->assertRedirect('https://checkout.paystack.com/abc123');
        $target = parse_url($response->headers->get('Location'), PHP_URL_SCHEME).'://'.parse_url($response->headers->get('Location'), PHP_URL_HOST);
        $this->assertContains($target, $this->csp($response)['form-action']);
    }

    public function test_json_image_and_webhook_responses_carry_the_headers(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');
        $this->giveLogo($school);
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        $this->assertBaseline($this->postJson('/pay/alpha/student-search', ['name' => 'x', 'admission_number' => 'y']));
        $logo = $this->get('/s/alpha/logo');
        $logo->assertOk();
        $this->assertSame('nosniff', $logo->headers->get('X-Content-Type-Options'));
        $this->assertBaseline($this->call('POST', '/paystack/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}'));
    }

    public function test_hsts_only_on_https_in_production(): void
    {
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'), 'HSTS outside production');

        $this->app['env'] = 'production';
        $secure = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'])->get('/');
        $this->assertSame('max-age=31536000', $secure->headers->get('Strict-Transport-Security'));
        $this->assertArrayHasKey('upgrade-insecure-requests', $this->csp($secure));

        // Plain HTTP in production is redirected by ForceHttps and gets no HSTS.
        $plain = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http'])->get('/');
        $this->assertNull($plain->headers->get('Strict-Transport-Security'));
    }

    public function test_error_pages_carry_the_headers(): void
    {
        $this->assertBaseline($this->get('/no-such-page')->assertNotFound());
    }

    public function test_no_view_contains_an_inline_script_without_the_nonce_or_an_inline_handler(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'emails'.DIRECTORY_SEPARATOR) || $file->getFilename() === 'receipt_pdf.blade.php') {
                continue; // rendered by mail clients and Dompdf, never under this CSP
            }
            $source = $file->getContents();

            preg_match_all('/<script\b([^>]*)>/i', $source, $m);
            foreach ($m[1] as $attributes) {
                $this->assertStringContainsString('@nonce', $attributes, $file->getRelativePathname().' has an inline script without @nonce');
            }
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input|load|error|key\w+|focus|blur|mouse\w+)\s*=/i', $source, $file->getRelativePathname().' has an inline event handler');
        }
    }
}
