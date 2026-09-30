<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Open Graph / X share metadata on the marketing layout. Opt-in: only the pages
 * that declare @section('share') carry it, and no page carries an og:url,
 * twitter:url or canonical until a production domain is confirmed.
 */
class SocialMetadataTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const SHARE_TAGS = [
        'og:type', 'og:site_name', 'og:title', 'og:description',
        'og:image', 'og:image:width', 'og:image:height', 'og:image:alt',
        'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image', 'twitter:image:alt',
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

    public function test_no_page_declares_a_url_or_canonical_before_the_domain_is_confirmed(): void
    {
        foreach (array_column(self::sharedPages(), 0) as $path) {
            $html = $this->get($path)->getContent();

            $this->assertStringNotContainsString('og:url', $html, $path);
            $this->assertStringNotContainsString('twitter:url', $html, $path);
            $this->assertStringNotContainsString('rel="canonical"', $html, $path);
        }
    }

    public function test_pages_that_did_not_opt_in_carry_no_share_tags(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');
        $transaction = $this->makeSuccessfulTransaction($school);

        $pages = [
            'sign in' => [$this->get('/admin/login'), 200],
            'forgot password' => [$this->get('/admin/forgot-password'), 200],
            'reset password' => [$this->get('/admin/reset-password/some-token?email=alpha%40example.test'), 200],
            'error page' => [$this->get('/no-such-page-anywhere'), 404],
            'public payment page' => [$this->get('/pay/alpha'), 200],
            'receipt' => [$this->get(URL::signedRoute('payment.receipt', ['transaction' => $transaction->id])), 200],
            'admin dashboard' => [$this->actingAsSchoolAdmin($school)->get('/admin/alpha/dashboard'), 200],
        ];

        foreach ($pages as $name => [$response, $status]) {
            $this->assertSame($status, $response->status(), $name);
            $this->assertSame([], $this->shareTags($response->getContent()), "{$name} carries share tags");
        }
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
