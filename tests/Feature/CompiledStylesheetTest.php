<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Styling is served as a compiled, self-hosted stylesheet (public/css/app.css, built
 * by the Tailwind CSS v3.4.17 standalone CLI) with self-hosted fonts, not compiled in
 * the browser by the Tailwind Play CDN or loaded from Google Fonts.
 */
class CompiledStylesheetTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const STYLESHEET = 'css/app.css';

    private const FONT_FILES = [
        'inter-v20-cyrillic-ext.woff2', 'inter-v20-cyrillic.woff2', 'inter-v20-greek-ext.woff2', 'inter-v20-greek.woff2',
        'inter-v20-vietnamese.woff2', 'inter-v20-latin-ext.woff2', 'inter-v20-latin.woff2',
        'plus-jakarta-sans-v12-cyrillic-ext.woff2', 'plus-jakarta-sans-v12-vietnamese.woff2',
        'plus-jakarta-sans-v12-latin-ext.woff2', 'plus-jakarta-sans-v12-latin.woff2',
    ];

    /**
     * Classes that are JavaScript or test hooks and deliberately carry no styling.
     * Every other class a page uses must have a rule in the compiled stylesheet.
     */
    private const UNSTYLED_HOOKS = ['js-toggle-password'];

    /** @return array<string, TestResponse> */
    private function renderedPages(): array
    {
        $school = $this->makeSchool('Alpha School', 'alpha');
        $this->giveLogo($school);
        $session = $this->makeSessionWithTerms($school);
        $this->makeFee($school, 'School Fees', 'Tuition', 50000, $session->terms()->first()->id);
        $this->makeStudent($school, 'ADM/001', 'Ada Obi');
        $transaction = $this->makeSuccessfulTransaction($school, ['reference' => 'alpha-ref-1']);

        Route::middleware('web')->get('/_stylesheet-test/server', fn () => throw new RuntimeException('boom'));
        config(['app.debug' => false]);

        $admin = fn () => $this->actingAsSchoolAdmin($school);

        $pages = [
            'home' => $this->get('/'),
            'contact' => $this->get('/contact'),
            'registration' => $this->get('/registration/create'),
            'sign-in' => $this->get('/admin/login'),
            'forgot password' => $this->get('/admin/forgot-password'),
            'reset password' => $this->get('/admin/reset-password/token?email=alpha%40example.test'),
            'privacy' => $this->get('/privacy'),
            'terms' => $this->get('/terms'),
            '404' => $this->get('/no-such-page'),
            '500' => $this->get('/_stylesheet-test/server'),
            'payment page' => $this->get('/pay/alpha'),
            'receipt' => $this->get(URL::signedRoute('payment.receipt', ['transaction' => $transaction->id])),
            'admin dashboard' => $admin()->get('/admin/alpha/dashboard'),
            'students' => $admin()->get('/admin/alpha/students'),
            'transactions' => $admin()->get('/admin/alpha/transactions'),
            'categories' => $admin()->get('/admin/alpha/categories'),
            'settings' => $admin()->get('/admin/alpha/settings'),
            'share' => $admin()->get('/admin/alpha/share'),
        ];

        $this->app->maintenanceMode()->activate(['retry' => 60, 'status' => 503]);
        try {
            $pages['503'] = $this->get('/contact');
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }

        return $pages;
    }

    public function test_every_layout_links_the_versioned_compiled_stylesheet_and_nothing_remote(): void
    {
        $version = substr(md5_file(public_path(self::STYLESHEET)), 0, 12);
        $link = '<link rel="stylesheet" href="'.asset(self::STYLESHEET).'?v='.$version.'">';

        foreach ($this->renderedPages() as $name => $response) {
            $html = $response->getContent();

            $this->assertSame(1, substr_count($html, $link), "{$name} links the compiled stylesheet once, versioned by its contents");
            foreach (['cdn.tailwindcss.com', 'text/tailwindcss', 'tailwind.config', 'fonts.googleapis.com', 'fonts.gstatic.com'] as $gone) {
                $this->assertStringNotContainsString($gone, $html, "{$name} still references {$gone}");
            }
        }
    }

    public function test_no_view_keeps_runtime_tailwind_or_google_fonts(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = file_get_contents($file->getPathname());
            foreach (['text/tailwindcss', 'cdn.tailwindcss.com', 'fonts.googleapis.com', 'fonts.gstatic.com', '@apply'] as $gone) {
                $this->assertStringNotContainsString($gone, $source, $file->getPathname());
            }
        }
    }

    public function test_the_compiled_stylesheet_exists_is_minified_and_contains_the_moved_rules(): void
    {
        $css = file_get_contents(public_path(self::STYLESHEET));

        // The @font-face rules (imported first) precede Tailwind's own banner.
        $this->assertSame(1, substr_count($css, '/*! tailwindcss v3.4.17 '), 'built by Tailwind CSS v3.4.17');
        $this->assertStringStartsWith('@font-face{', $css);
        $this->assertLessThanOrEqual(2, substr_count($css, "\n"), 'minified');

        // One rule from each block that used to be compiled in the browser.
        foreach ([
            '.btn-obsidian{', '.field-input{', '.container-x{',   // head-tokens
            '.side-link{', '.badge{',                            // admin layout
            '.admin-table tbody tr', '.admin-table--stacked',     // admin table component
            'html.payment-page', '.step-card{',                   // payment page
            '.receipt-row{', 'body.receipt-page',                 // receipt (incl. print rule)
            '.policy h2{',                                        // privacy / terms
            '.slim-header~#main{',                                // slim-header tint, scoped
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, "missing {$selector}");
        }
    }

    public function test_the_fonts_are_self_hosted_with_the_original_subsets(): void
    {
        $css = file_get_contents(public_path(self::STYLESHEET));

        $this->assertSame(40, substr_count($css, '@font-face'), 'the 40 @font-face rules Google Fonts served');
        $this->assertSame(40, substr_count($css, 'font-display:swap'));

        foreach (self::FONT_FILES as $font) {
            $path = public_path('fonts/'.$font);
            $this->assertFileExists($path);
            $this->assertSame('wOF2', substr((string) file_get_contents($path), 0, 4), "{$font} is a WOFF2 file");
            $this->assertStringContainsString('url(../fonts/'.$font.')', $css);
        }

        preg_match_all('/url\(([^)]+)\)/', $css, $urls);
        $this->assertSame([], array_values(array_filter($urls[1], fn ($u) => ! str_starts_with($u, '../fonts/'))), 'no remote font URLs');
        $this->assertFileExists(public_path('fonts/OFL-Inter.txt'));
        $this->assertFileExists(public_path('fonts/OFL-PlusJakartaSans.txt'));
    }

    public function test_every_class_used_by_the_rendered_pages_has_a_compiled_rule(): void
    {
        $css = file_get_contents(public_path(self::STYLESHEET));
        $missing = [];

        foreach ($this->renderedPages() as $name => $response) {
            preg_match_all('/\bclass="([^"]*)"/', $response->getContent(), $attributes);
            foreach ($attributes[1] as $attribute) {
                foreach (preg_split('/\s+/', trim(html_entity_decode($attribute)), -1, PREG_SPLIT_NO_EMPTY) as $class) {
                    if (in_array($class, self::UNSTYLED_HOOKS, true)) {
                        continue;
                    }
                    if (! str_contains($css, '.'.$this->cssEscape($class))) {
                        $missing[$class][] = $name;
                    }
                }
            }
        }

        $this->assertSame([], array_map(fn ($pages) => implode(', ', array_unique($pages)), $missing), 'classes with no compiled rule');
    }

    public function test_classes_toggled_by_inline_javascript_are_compiled(): void
    {
        $css = file_get_contents(public_path(self::STYLESHEET));

        // classList / className targets in the admin layout, confirm dialog, marketing
        // nav, submit button and payment page scripts.
        foreach (['hidden', 'inline-flex', 'overflow-hidden', 'opacity-60', 'opacity-70', 'cursor-not-allowed',
            'text-red-600', 'font-medium', 'text-brand-slate', 'bg-red-600', 'hover:bg-red-700', 'focus-visible:ring-red-500/30',
            '[&.is-open]:visible', '[&.is-open]:translate-x-0'] as $class) {
            $this->assertStringContainsString('.'.$this->cssEscape($class), $css, "missing .{$class}");
        }
    }

    /** A class name escaped the way Tailwind writes it in a selector. */
    private function cssEscape(string $class): string
    {
        // Tailwind writes a comma as the hex escape "\2c " and backslash-escapes the rest.
        $escaped = preg_replace_callback('/[^a-zA-Z0-9_-]/', fn ($m) => $m[0] === ',' ? '\\2c ' : '\\'.$m[0], $class);

        // A leading digit is written as a hex escape: "2xl" -> "\32 xl".
        return preg_replace_callback('/^([0-9])/', fn ($m) => '\\3'.$m[1].' ', $escaped);
    }
}
