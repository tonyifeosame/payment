<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Regression tests for F12 / HIGH-1 — the reverse proxy was not trusted.
 *
 * Render terminates TLS at its edge and forwards to the container over plain HTTP,
 * so X-Forwarded-* is the only record of the original request. Without
 * trustProxies() Laravel saw scheme=http and the proxy's own IP, which meant a
 * receipt URL signed as https was validated as http and rejected — every emailed
 * receipt link was a dead link.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    /** Headers exactly as Render's edge presents them. */
    private const PROXY = [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        'HTTP_HOST' => 'laravel-app.onrender.test',
    ];

    private function proxiedRequest(string $uri = '/'): \Illuminate\Http\Request
    {
        // Built as HTTP, because that is how it actually reaches the container.
        $request = \Illuminate\Http\Request::create('http://laravel-app.onrender.test'.$uri, 'GET', [], [], [], self::PROXY);

        $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        return $request;
    }

    public function test_forwarded_proto_makes_the_request_secure(): void
    {
        $request = $this->proxiedRequest();

        $this->assertTrue($request->isFromTrustedProxy(), 'the reverse proxy is not trusted');
        $this->assertTrue($request->secure(), 'a TLS request through the proxy was seen as insecure');
        $this->assertSame('https', $request->getScheme());
    }

    public function test_the_real_client_ip_is_recognised(): void
    {
        // Without this, every request looks like it comes from the proxy and the
        // registration throttle becomes one global bucket instead of per-IP.
        $this->assertSame('203.0.113.9', $this->proxiedRequest()->ip());
    }

    public function test_a_signed_receipt_url_survives_the_round_trip_through_the_proxy(): void
    {
        $school = School::create([
            'name' => 'Greenfield', 'slug' => 'greenfield', 'email' => 'g@example.test',
            'admin_password' => Hash::make('password123'),
        ]);
        $transaction = Transaction::create([
            'school_id' => $school->id, 'reference' => 'proxy-ref-1', 'amount' => 51250.00,
            'status' => 'success', 'email' => 'payer@example.test', 'name' => 'Ada Parent',
            'meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250],
        ]);

        // The receipt page builds its download link with URL::signedRoute() while
        // serving a request, which is how the real link is produced. Serving it
        // through the proxy is what makes the generator see the forwarded scheme.
        $html = $this->withSession(['last_transaction_id' => $transaction->id])
            ->withServerVariables(self::PROXY)
            ->get('/payment/receipt/'.$transaction->id)
            ->assertOk()
            ->getContent();

        preg_match('/href="([^"]*download\?[^"]*signature=[^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'the receipt page did not render a signed download link');
        $signed = html_entity_decode($m[1]);

        $this->assertSame('https', parse_url($signed, PHP_URL_SCHEME), 'the link was signed as http behind a TLS proxy');

        // The click comes back to the container over plain HTTP with the forwarded
        // header, exactly as a click from an email arrives.
        $path = parse_url($signed, PHP_URL_PATH).'?'.parse_url($signed, PHP_URL_QUERY);
        $returning = \Illuminate\Http\Request::create(
            'http://'.parse_url($signed, PHP_URL_HOST).$path, 'GET', [], [], [], self::PROXY
        );

        $this->assertTrue($returning->hasValidSignature(), 'a signed receipt link was rejected after passing through the proxy');

        // And it is actually servable with no session at all.
        $this->flushSession();
        $this->withServerVariables(self::PROXY)->get($signed)->assertOk();
    }

    public function test_force_https_does_not_redirect_a_request_that_arrived_over_tls(): void
    {
        // ForceHttps only acts in production, so assert against that environment.
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $request = \Illuminate\Http\Request::create('http://laravel-app.onrender.test/', 'GET', [], [], [], self::PROXY);
        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        $this->assertNotSame(301, $response->getStatusCode(), 'a TLS request behind the proxy was redirected anyway');
    }

    public function test_force_https_still_redirects_plain_http(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $request = \Illuminate\Http\Request::create('http://laravel-app.onrender.test/', 'GET');
        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringStartsWith('https://', (string) $response->headers->get('Location'));
    }

    public function test_the_dead_trust_proxies_middleware_is_gone(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Http/Middleware/TrustProxies.php'));
        $this->assertFalse(class_exists(\App\Http\Middleware\TrustProxies::class));
    }
}
