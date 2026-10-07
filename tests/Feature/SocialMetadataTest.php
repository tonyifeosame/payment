<?php

namespace Tests\Feature;

use App\Support\AppUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Open Graph / X share metadata and canonical links on the marketing layout.
 * Both are opt-in: only the pages that declare @section('share') carry share
 * tags, and only the indexable pages (@section('canonical')) a canonical link.
 * Every page URL is built from APP_URL, never from the request's host.
 */
class SocialMetadataTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const SHARE_TAGS = [
        'og:type', 'og:url', 'og:site_name', 'og:title', 'og:description',
        'og:image', 'og:image:width', 'og:image:height', 'og:image:alt',
        'twitter:card', 'twitter:url', 'twitter:title', 'twitter:description', 'twitter:image', 'twitter:image:alt',
    ];

    /** @return array<string, array{string, string, string}> path, share title, share description */
    public static function sharedPages(): array
    {
        return [
            'home' => ['/', 'FEYRA — School fees, collected without the stress', 'FEYRA gives parents a simple way to pay school fees online while the school tracks every payment, receipt and payout in one place.'],
            'contact' => ['/contact', 'Contact us — FEYRA', 'Questions about collecting school fees with FEYRA? Send us a message or reach us on WhatsApp.'],
            'registration' => ['/registration/create', 'Set up your school — FEYRA', 'Create a school account to collect school fees online, send receipts automatically and track payouts to your bank.'],
        ];
    }

    #[DataProvider('sharedPages')]
    public function test_shared_page_carries_one_of_each_share_tag_from_its_own_title_and_description(string $path, string $title, string $description): void
    {
        $tags = $this->shareTags($this->get($path)->assertOk()->getContent());

        $this->assertEqualsCanonicalizing(self::SHARE_TAGS, array_keys($tags));
        foreach ($tags as $key => $values) {
            $this->assertCount(1, $values, "{$key} appears more than once");
        }

        $this->assertSame('website', $tags['og:type'][0]);
        $this->assertSame(AppUrl::to($path), $tags['og:url'][0]);
        $this->assertSame($tags['og:url'][0], $tags['twitter:url'][0]);
        $this->assertSame('FEYRA', $tags['og:site_name'][0]);
        $this->assertSame($title, $tags['og:title'][0]);
        $this->assertSame($title, $tags['twitter:title'][0]);
        $this->assertSame($description, $tags['og:description'][0]);
        $this->assertSame($description, $tags['twitter:description'][0]);
        $this->assertSame('summary_large_image', $tags['twitter:card'][0]);
        $this->assertSame(['1200', '630'], [$tags['og:image:width'][0], $tags['og:image:height'][0]]);
        $this->assertSame(asset('images/feyra-og.png'), $tags['og:image'][0]);
        $this->assertSame($tags['og:image'][0], $tags['twitter:image'][0]);
        $this->assertNotSame('', $tags['og:image:alt'][0]);
    }

    public function test_share_text_is_plain_and_escaped_exactly_once(): void
    {
        // A section set with @section('x', '…') arrives already escaped; a block
        // section spans lines. Neither may leak into the tag as-is.
        $html = Blade::render(<<<'BLADE'
            @extends('layouts.marketing')
            @section('title')
                Tom & Jerry's school — @include('marketing.partials.brand-name')
            @endsection
            @section('meta_description', 'Fees for "Tom & Jerry\'s" school.')
            @section('share', 'on')
            @section('content') page @endsection
            BLADE);

        $this->assertStringContainsString('<meta property="og:title" content="Tom &amp; Jerry&#039;s school — FEYRA">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Fees for &quot;Tom &amp; Jerry&#039;s&quot; school.">', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('&amp;#039;', $html);
    }

    public function test_image_url_follows_the_requesting_host_rather_than_a_fixed_domain(): void
    {
        $tags = $this->shareTags($this->get('https://feyra.example/')->assertOk()->getContent());

        $this->assertSame('https://feyra.example/images/feyra-og.png', $tags['og:image'][0]);
        $this->assertSame('https://feyra.example/images/feyra-og.png', $tags['twitter:image'][0]);
    }

    public function test_share_image_is_a_1200_by_630_png_of_reasonable_size(): void
    {
        $file = public_path('images/feyra-og.png');

        $this->assertFileExists($file);
        [$width, $height, $type] = getimagesize($file);
        $this->assertSame([1200, 630, IMAGETYPE_PNG], [$width, $height, $type]);
        $this->assertLessThan(300 * 1024, filesize($file));
    }

    /** @return array<string, array{string}> */
    public static function indexablePages(): array
    {
        return [
            'home' => ['/'],
            'registration' => ['/registration/create'],
            'contact' => ['/contact'],
            'privacy' => ['/privacy'],
            'terms' => ['/terms'],
        ];
    }

    #[DataProvider('indexablePages')]
    public function test_indexable_page_has_exactly_one_canonical_link_on_app_url(string $path): void
    {
        // The shared pages' og:url is checked against the same AppUrl::to($path)
        // above, so canonical and og:url always agree.
        $this->assertSame([AppUrl::to($path)], $this->canonicalLinks($this->get($path)->assertOk()->getContent()));
    }

    public function test_page_urls_come_from_app_url_not_the_request_host_or_query(): void
    {
        config(['app.url' => 'https://feyra.site']);

        $html = $this->get('https://feyra-app.onrender.com/contact?utm_source=whatsapp')->assertOk()->getContent();
        $tags = $this->shareTags($html);

        $this->assertSame(['https://feyra.site/contact'], $this->canonicalLinks($html));
        $this->assertSame('https://feyra.site/contact', $tags['og:url'][0]);
        $this->assertSame('https://feyra.site/contact', $tags['twitter:url'][0]);

        $this->assertSame(['https://feyra.site/'], $this->canonicalLinks($this->get('https://feyra-app.onrender.com/')->getContent()));
    }

    public function test_every_shared_page_is_also_an_indexable_page(): void
    {
        $this->assertSame(
            [],
            array_diff(array_column(self::sharedPages(), 0), array_column(self::indexablePages(), 0)),
        );
    }

    public function test_pages_that_did_not_opt_in_carry_no_share_tags(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');
        $transaction = $this->makeSuccessfulTransaction($school);

        // The public payment page has its own link-preview metadata (no longer opted
        // out): see PaymentPageMetadataTest.
        $pages = [
            'sign in' => [$this->get('/admin/login'), 200],
            'forgot password' => [$this->get('/admin/forgot-password'), 200],
            'reset password' => [$this->get('/admin/reset-password/some-token?email=alpha%40example.test'), 200],
            'error page' => [$this->get('/no-such-page-anywhere'), 404],
            'receipt' => [$this->get(URL::signedRoute('payment.receipt', ['transaction' => $transaction->id])), 200],
            'admin dashboard' => [$this->actingAsSchoolAdmin($school)->get('/admin/alpha/dashboard'), 200],
        ];

        foreach ($pages as $name => [$response, $status]) {
            $this->assertSame($status, $response->status(), $name);
            $this->assertSame([], $this->shareTags($response->getContent()), "{$name} carries share tags");
            $this->assertSame([], $this->canonicalLinks($response->getContent()), "{$name} claims a canonical URL");
        }
    }

    /** @return list<string> every canonical link's href, decoded */
    private function canonicalLinks(string $html): array
    {
        preg_match_all('/<link\s+rel="canonical"\s+href="([^"]*)"\s*>/', $html, $matches);
        $this->assertSame(count($matches[1]), substr_count($html, 'rel="canonical"'));

        return array_map(fn ($href) => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches[1]);
    }

    /**
     * Every og:* / twitter:* meta tag, decoded, grouped by key.
     *
     * @return array<string, list<string>>
     */
    private function shareTags(string $html): array
    {
        preg_match_all('/<meta\s+(?:property|name)="((?:og|twitter):[^"]+)"\s+content="([^"]*)"\s*>/u', $html, $matches, PREG_SET_ORDER);

        $tags = [];
        foreach ($matches as [, $key, $content]) {
            $tags[$key][] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Catch any share tag the pattern above would not parse.
        $this->assertSame(count($matches), preg_match_all('/(?:property|name)="(?:og|twitter):/', $html));

        return $tags;
    }
}
