<?php

namespace Tests\Feature;

use App\Http\Controllers\SitemapController;
use App\Support\AppUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * /sitemap.xml and public/robots.txt: the indexable pages on APP_URL, each
 * matching that page's own canonical link, and nothing that is noindex.
 */
class SitemapTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    public function test_the_sitemap_lists_the_indexable_pages_on_app_url(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));
        $this->assertSame(
            array_map(fn ($name) => AppUrl::to(route($name, [], false)), SitemapController::PAGES),
            $this->locs($response->getContent()),
        );
    }

    public function test_every_listed_url_is_that_pages_canonical_link(): void
    {
        foreach ($this->locs($this->get('/sitemap.xml')->getContent()) as $loc) {
            $html = $this->get($loc)->assertOk()->getContent();

            $this->assertStringContainsString('<link rel="canonical" href="'.e($loc).'">', $html, $loc);
            $this->assertStringNotContainsString('noindex', $html, $loc);
        }
    }

    public function test_urls_come_from_app_url_whatever_host_was_asked(): void
    {
        config(['app.url' => 'https://feyra.site']);

        $locs = $this->locs($this->get('https://feyra-app.onrender.com/sitemap.xml')->assertOk()->getContent());

        $this->assertContains('https://feyra.site/', $locs);
        $this->assertContains('https://feyra.site/privacy', $locs);
        foreach ($locs as $loc) {
            $this->assertStringStartsWith('https://feyra.site/', $loc);
        }
    }

    public function test_noindex_pages_are_left_out(): void
    {
        $this->makeSchool('Alpha School', 'alpha');

        foreach ($this->locs($this->get('/sitemap.xml')->getContent()) as $loc) {
            $path = (string) parse_url($loc, PHP_URL_PATH);
            foreach (['/pay/', '/s/', '/admin', '/payment'] as $prefix) {
                $this->assertStringStartsNotWith($prefix, $path, $loc);
            }
        }
    }

    public function test_robots_txt_allows_crawling_and_names_the_production_sitemap(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertMatchesRegularExpression('/^User-agent: \*$/m', $robots);
        $this->assertMatchesRegularExpression('/^Disallow:$/m', $robots);
        $this->assertSame(1, preg_match_all('/^Sitemap: (.+)$/m', $robots, $m));
        $this->assertSame('https://feyra.site/sitemap.xml', $m[1][0]);
    }

    /** @return list<string> */
    private function locs(string $xml): array
    {
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc, 'the sitemap is well-formed XML');
        $this->assertSame('urlset', $doc->getName());
        $this->assertSame(['' => 'http://www.sitemaps.org/schemas/sitemap/0.9'], $doc->getDocNamespaces());

        $locs = [];
        foreach ($doc->url as $url) {
            $locs[] = (string) $url->loc;
        }

        return $locs;
    }
}
