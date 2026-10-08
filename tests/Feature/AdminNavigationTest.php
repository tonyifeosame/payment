<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The admin sidebar is five entries — Dashboard, Students, Fees, Payments,
 * Settings — and the pages that used to be separate entries are still reachable
 * from inside their section. Nothing was removed to shorten the menu.
 */
class AdminNavigationTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
    }

    /** @return array<int, string> the sidebar's link labels, in order */
    private function sidebarLabels(string $html): array
    {
        $nav = substr($html, strpos($html, '<nav class="flex-1'));
        $nav = substr($nav, 0, strpos($nav, '</nav>'));
        preg_match_all('/<a href="[^"]*" class="side-link[^"]*"[^>]*>.*?<\/svg>\s*(.*?)\s*<\/a>/s', $nav, $m);

        return $m[1];
    }

    public function test_the_sidebar_has_exactly_five_entries(): void
    {
        $html = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard')->assertOk()->getContent();

        $this->assertSame(['Dashboard', 'Students', 'Fees', 'Payments', 'Settings'], $this->sidebarLabels($html));
        foreach (['Sessions', 'Fee Types', 'Categories', 'Transactions', 'Payouts', 'Share'] as $gone) {
            $this->assertNotContains($gone, $this->sidebarLabels($html));
        }
        $this->assertStringNotContainsString('/admin/alpha/sessions', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function sections(): array
    {
        return [
            'students' => ['/admin/alpha/students', 'Students', ['/admin/alpha/students/classes', '/admin/alpha/students/promotion']],
            'classes' => ['/admin/alpha/students/classes', 'Students', ['/admin/alpha/students', '/admin/alpha/students/promotion']],
            'promotion' => ['/admin/alpha/students/promotion', 'Students', ['/admin/alpha/students/classes']],
            'fees' => ['/admin/alpha/subcategories', 'Fees', ['/admin/alpha/categories', '/admin/alpha/subcategories/create']],
            'categories' => ['/admin/alpha/categories', 'Fees', ['/admin/alpha/subcategories']],
            'payment history' => ['/admin/alpha/transactions', 'Payments', ['/admin/alpha/payouts']],
            'payouts' => ['/admin/alpha/payouts', 'Payments', ['/admin/alpha/transactions']],
            'settings' => ['/admin/alpha/settings', 'Settings', ['/admin/alpha/share']],
            'share' => ['/admin/alpha/share', 'Settings', ['/admin/alpha/settings']],
        ];
    }

    /** @param array<int, string> $links */
    #[DataProvider('sections')]
    public function test_each_page_highlights_its_section_and_links_to_its_sibling_pages(string $url, string $section, array $links): void
    {
        $html = $this->actingAsSchoolAdmin($this->alpha)->get($url)->assertOk()->getContent();

        // Exactly one sidebar entry is current, and it is this page's section.
        preg_match_all('/class="side-link is-active"\s+aria-current="page"\s*>.*?<\/svg>\s*(.*?)\s*<\/a>/s', $html, $m);
        $this->assertSame([$section], $m[1]);

        // The section's own pages are one click away.
        $this->assertStringContainsString('aria-label="'.$section.' pages"', $html);
        foreach ($links as $link) {
            $this->assertStringContainsString('href="http://localhost'.$link.'"', $html);
        }
    }
}
