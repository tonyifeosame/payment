<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The public marketing homepage. These tests pin the copy and links the page is
 * allowed to make, and guard against claims the product does not back up.
 */
class HomepageTest extends TestCase
{
    public function test_homepage_renders_the_marketing_page(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('FEYRA');
        $response->assertSee('School fees.');
        $response->assertSee('Collected without the stress.');
        $response->assertSee('Give parents a simple way to pay school fees online while your school tracks every payment in one place.');
        $response->assertSee('Ready to simplify school fee collection?');

        // Primary calls to action point at real routes.
        $response->assertSee(route('registration.create'));
        $response->assertSee(route('admin.login'));
        $response->assertSee(route('contact.show'));

        // Find Your School is preserved.
        $response->assertSee('id="schoolSlug"', false);
        $response->assertSee('id="goSchool"', false);
    }

    public function test_homepage_labels_demo_data_and_mirrors_the_real_navigation(): void
    {
        $response = $this->get('/');

        $response->assertSee('Demo data');
        $response->assertSee('Demo School');
        foreach (['Dashboard', 'Students', 'Sessions', 'Categories', 'Fee Types', 'Transactions', 'Payouts', 'Share', 'Settings'] as $item) {
            $response->assertSee($item);
        }
        foreach (['Paid to bank', 'On the way', 'Needs attention'] as $label) {
            $response->assertSee($label);
        }
    }

    public function test_homepage_makes_no_unsupported_claims(): void
    {
        $html = $this->get('/')->getContent();

        foreach ([
            'Outstanding',      // no expected-fee / balance ledger exists
            'Reports',          // no reports module exists
            '9AM',              // payouts are immediate, not scheduled
            'Pricing',          // no public pricing page or claim
            'Privacy', 'Terms', // no legal pages yet
            'Greenwood',        // reference-image artefact
            'AccessLink',       // previous project name
            'Lemon Squeezy',
            'schools already',  // social proof
            'trusted by',
            'encrypted',
            'uptime',
            'PCI DSS', 'certified', 'compliant',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $html, "Homepage must not contain \"{$forbidden}\".");
        }
    }

    public function test_every_internal_homepage_link_resolves(): void
    {
        $html = $this->get('/')->getContent();

        preg_match_all('/href="([^"#]+)"/', $html, $matches);

        $base = rtrim(config('app.url'), '/');
        $paths = collect($matches[1])
            ->filter(fn (string $href) => str_starts_with($href, $base.'/') || (str_starts_with($href, '/') && ! str_starts_with($href, '//')))
            ->map(fn (string $href) => parse_url($href, PHP_URL_PATH) ?: '/')
            ->reject(fn (string $path) => str_contains($path, '.')) // static files (favicon.svg) are served by the web server, not the router
            ->unique()
            ->values();

        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->get($path)->assertOk();
        }
    }
}
