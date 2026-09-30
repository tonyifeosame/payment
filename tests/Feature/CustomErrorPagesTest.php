<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The branded HTTP error pages in resources/views/errors. Each must keep Laravel's
 * status code, render on the FEYRA marketing shell, and never echo the exception —
 * its message, class, trace or any identifier it carries.
 */
class CustomErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Planted in exception messages; none of it may reach the page. */
    private const SECRET = 'SQLSTATE[42P01] sk_live_leakcheck ref=PO-11111111-2222 transaction_id=4242';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::get('/_error-test/server', fn () => throw new RuntimeException(self::SECRET));
            Route::post('/_error-test/expired', fn () => throw new TokenMismatchException(self::SECRET));
            Route::get('/_error-test/throttled', fn () => 'ok')->middleware('throttle:1,1,error-page-test');
            Route::get('/_error-test/unavailable', fn () => abort(503, self::SECRET));
        });
    }

    public function test_unknown_url_renders_the_branded_404_with_a_way_home(): void
    {
        $response = $this->get('/no-such-page-anywhere')->assertNotFound();

        $this->assertBrandedErrorPage($response, '404', 'Page not found');
        $response->assertSee('href="'.route('home').'"', false);
    }

    public function test_missing_model_404_does_not_reveal_the_model_or_query(): void
    {
        $response = $this->get('/pay/no-such-school')->assertNotFound();

        $this->assertBrandedErrorPage($response, '404', 'Page not found');
        $response->assertDontSee('No query results', false)
            ->assertDontSee('App\\Models', false);
    }

    public function test_json_requests_keep_laravels_json_error_responses(): void
    {
        $this->getJson('/no-such-page-anywhere')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message'])
            ->assertDontSee('Page not found');
    }

    public function test_expired_session_renders_419_with_a_retry_link_to_the_previous_page(): void
    {
        $response = $this->withSession(['_previous' => ['url' => url('/contact')]])
            ->post('/_error-test/expired')
            ->assertStatus(419);

        $this->assertBrandedErrorPage($response, '419', 'This page has expired');
        $response->assertSee('session expired', false)
            ->assertSee('href="'.url('/contact').'"', false)
            ->assertSee('Reload and try again');
    }

    public function test_419_after_the_session_expired_retries_the_same_site_referer(): void
    {
        // An expired session has no record of the previous page; the browser's
        // Referer for the form page is what is left.
        $this->withHeader('Referer', url('/contact'))
            ->post('/_error-test/expired')
            ->assertStatus(419)
            ->assertSee('href="'.url('/contact').'"', false);
    }

    public function test_419_retry_link_never_follows_an_off_site_referer(): void
    {
        $response = $this->withHeader('Referer', 'https://evil.example/phish')
            ->post('/_error-test/expired')
            ->assertStatus(419);

        $response->assertDontSee('evil.example', false)
            ->assertSee('href="'.route('home').'"', false);
    }

    public function test_throttled_request_renders_429_and_keeps_the_retry_after_header(): void
    {
        $this->get('/_error-test/throttled')->assertOk();

        $response = $this->get('/_error-test/throttled')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertBrandedErrorPage($response, '429', 'Too many requests');
        $response->assertSee('wait a few minutes', false);
    }

    public function test_unhandled_exception_renders_a_generic_500_without_technical_details(): void
    {
        config(['app.debug' => false]); // as in production (render.yaml)

        $response = $this->get('/_error-test/server')->assertStatus(500);

        $this->assertBrandedErrorPage($response, '500', 'Something went wrong');
        $response->assertDontSee('RuntimeException', false)
            ->assertDontSee('Stack trace', false)
            ->assertDontSee(base_path(), false);
    }

    public function test_aborted_503_renders_the_branded_unavailable_page(): void
    {
        $response = $this->get('/_error-test/unavailable')->assertStatus(503);

        $this->assertBrandedErrorPage($response, '503', 'We’ll be back shortly');
    }

    public function test_maintenance_mode_renders_the_branded_503_without_a_session(): void
    {
        $this->app->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);

        try {
            $response = $this->get('/contact?from=link')
                ->assertStatus(503)
                ->assertHeader('Retry-After', '60');

            $this->assertBrandedErrorPage($response, '503', 'We’ll be back shortly');
            $response->assertSee('maintenance', false)
                ->assertSee('href="'.url('/contact?from=link').'"', false);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    private function assertBrandedErrorPage(TestResponse $response, string $code, string $heading): void
    {
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Error '.$code)
            ->assertSee($heading)
            ->assertSee('FEYRA')
            ->assertSee('href="'.route('contact.show').'"', false)
            ->assertDontSee('SQLSTATE', false)
            ->assertDontSee('sk_live_leakcheck', false)
            ->assertDontSee('PO-11111111', false)
            ->assertDontSee('transaction_id', false);
    }
}
