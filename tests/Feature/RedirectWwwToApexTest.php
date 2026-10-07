<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * www.feyra.site -> feyra.site (RedirectWwwToApex), the fallback for when
 * Render's own www redirect is not in place.
 */
class RedirectWwwToApexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://feyra.site']);
    }

    public function test_www_is_sent_permanently_to_the_apex_with_path_and_query(): void
    {
        $this->get('https://www.feyra.site/contact?utm_source=whatsapp')
            ->assertStatus(301)
            ->assertRedirect('https://feyra.site/contact?utm_source=whatsapp');

        $this->get('https://WWW.FEYRA.SITE/')->assertStatus(301)->assertRedirect('https://feyra.site/');
    }

    public function test_a_post_to_www_keeps_its_method(): void
    {
        $this->post('https://www.feyra.site/paystack/webhook', ['event' => 'x'])
            ->assertStatus(308)
            ->assertRedirect('https://feyra.site/paystack/webhook');
    }

    public function test_plain_http_www_reaches_https_apex_in_one_hop_in_production(): void
    {
        config(['app.url' => 'http://feyra.site']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('http://www.feyra.site/terms')
            ->assertStatus(301)
            ->assertRedirect('https://feyra.site/terms');
    }

    public function test_every_other_host_is_left_alone(): void
    {
        $this->get('https://feyra.site/contact')->assertOk();
        $this->get('https://feyra-app.onrender.com/contact')->assertOk();
        $this->get('https://www.feyra-app.onrender.com/contact')->assertOk();
        $this->get('https://wwwfeyra.site/contact')->assertOk();
        $this->get('https://www.www.feyra.site/contact')->assertOk();
    }

    public function test_no_redirect_when_app_url_is_itself_the_www_host(): void
    {
        config(['app.url' => 'https://www.feyra.site']);

        $this->get('https://www.feyra.site/contact')->assertOk();
    }
}
