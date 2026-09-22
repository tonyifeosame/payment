<?php

namespace Tests\Feature;

use App\Mail\SchoolLinksMail;
use App\Mail\SchoolPasswordResetMail;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L5 — the two emails the FEYRA rebrand missed, and the dead views it left behind.
 *
 * `school_links` and `school_password_reset` were still the pre-rebrand
 * templates: Arial on a sky-blue heading, no mark, no wordmark, and the fee
 * types listed as "Subcategories" — a word the admin area itself stopped using.
 * The mailables are deliberately untouched, so every link, token and expiry rule
 * is exactly what it was; only the template that renders them changed.
 */
class BrandedSchoolEmailsTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    /** The pre-rebrand palette these templates used to carry. */
    private const OLD_COLOURS = ['#0ea5e9', '#0f172a', '#475569'];

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
    }

    private function links(): array
    {
        return [
            'dashboard' => 'https://example.test/admin/alpha/dashboard',
            'payment' => 'https://example.test/pay/alpha',
            'categories' => 'https://example.test/admin/alpha/categories',
            'subcategories' => 'https://example.test/admin/alpha/subcategories',
            'transactions' => 'https://example.test/admin/alpha/transactions',
        ];
    }

    // ------------------------------------------------------- school links mail

    public function test_the_links_email_carries_the_feyra_brand(): void
    {
        $html = (new SchoolLinksMail($this->school, $this->links()))->render();

        $this->assertStringContainsString('FEYRA', $html);
        $this->assertStringContainsString('images/feyra-mark.png', $html);
        $this->assertStringContainsString('#121217', $html);   // Obsidian
        $this->assertStringContainsString('#5423E7', $html);   // Royal Violet
        $this->assertStringContainsString('Plus Jakarta Sans', $html);

        foreach (self::OLD_COLOURS as $colour) {
            $this->assertStringNotContainsString($colour, $html, "the pre-rebrand colour {$colour} is still in the links email");
        }
        // Arial survives as the last fallback in the new stack; what must be gone
        // is Arial as the PRIMARY family, which is what the old template declared.
        $this->assertStringNotContainsString('font-family: Arial', $html);
    }

    public function test_the_links_email_says_fee_types_not_subcategories(): void
    {
        $html = (new SchoolLinksMail($this->school, $this->links()))->render();

        $this->assertStringContainsString('Fee types', $html);
        $this->assertStringNotContainsString('Subcategories', $html);
        $this->assertStringNotContainsString('subcategories:', strtolower($html));
    }

    public function test_every_link_the_controller_supplied_still_appears_unchanged(): void
    {
        $links = $this->links();
        $html = (new SchoolLinksMail($this->school, $links))->render();

        foreach ($links as $key => $url) {
            $this->assertStringContainsString($url, $html, "the {$key} link is missing from the email");
            $this->assertStringContainsString('href="'.$url.'"', $html, "the {$key} link is not a link");
        }

        $this->assertStringContainsString('Alpha School', $html);
    }

    public function test_the_links_email_omits_a_link_the_controller_did_not_supply(): void
    {
        // The dashboard link is conditional in the controller; the template must
        // not print an empty row for it.
        $links = $this->links();
        unset($links['dashboard']);

        $html = (new SchoolLinksMail($this->school, $links))->render();

        $this->assertStringNotContainsString('Dashboard', $html);
        $this->assertStringContainsString($links['payment'], $html);
    }

    // ------------------------------------------------- password reset mail

    public function test_the_reset_email_carries_the_feyra_brand(): void
    {
        $html = (new SchoolPasswordResetMail($this->school, 'https://example.test/admin/reset-password/tok-123?email=alpha%40example.test'))->render();

        $this->assertStringContainsString('FEYRA', $html);
        $this->assertStringContainsString('images/feyra-mark.png', $html);
        $this->assertStringContainsString('#121217', $html);
        $this->assertStringContainsString('Plus Jakarta Sans', $html);

        foreach (self::OLD_COLOURS as $colour) {
            $this->assertStringNotContainsString($colour, $html, "the pre-rebrand colour {$colour} is still in the reset email");
        }
    }

    public function test_the_reset_link_and_its_token_survive_the_rebrand(): void
    {
        $link = 'https://example.test/admin/reset-password/tok-123?email=alpha%40example.test';

        $html = (new SchoolPasswordResetMail($this->school, $link))->render();

        // Present as the button target AND as a copyable fallback, unaltered.
        $this->assertStringContainsString('href="'.$link.'"', $html);
        $this->assertStringContainsString('tok-123', $html);
        // Three times: the button's href, the fallback's href, and the fallback's
        // visible text — and identical in all three.
        $this->assertSame(3, substr_count($html, $link), 'the reset link should appear as the button and as a copyable fallback');
        $this->assertStringContainsString('Reset password', $html);
    }

    public function test_the_reset_email_still_states_the_configured_expiry(): void
    {
        config(['auth.passwords.users.expire' => 45]);

        $html = (new SchoolPasswordResetMail($this->school, 'https://example.test/r/tok'))->render();

        $this->assertStringContainsString('45 minutes', $html);
        $this->assertStringContainsString('used once', $html);
    }

    public function test_the_reset_email_never_carries_a_password_or_hash(): void
    {
        $html = (new SchoolPasswordResetMail($this->school->fresh(), 'https://example.test/r/tok'))->render();

        $this->assertStringNotContainsString((string) $this->school->fresh()->admin_password, $html);
        $this->assertStringNotContainsString('$2y$', $html);
        $this->assertStringNotContainsString('password123', $html);
    }

    // ------------------------------------------------------------ dead views

    public function test_the_dead_views_are_gone(): void
    {
        foreach ([
            'resources/views/login.blade.php',
            'resources/views/welcome.blade.php',
            'resources/views/transactions/create.blade.php',
        ] as $path) {
            $this->assertFalse(File::exists(base_path($path)), "{$path} should have been removed");
        }

        // components/app-layout.blade.php is deliberately KEPT. It was outside the
        // L5 list, and although transactions/create was its only consumer, removing
        // it is a separate decision. Nothing uses it now — see the reference test
        // below, which asserts no view carries <x-app-layout.
        $this->assertTrue(File::exists(base_path('resources/views/components/app-layout.blade.php')));
    }

    public function test_nothing_references_the_removed_views(): void
    {
        $sources = collect(File::allFiles(base_path('resources/views')))
            ->merge(File::allFiles(base_path('app')))
            ->merge(File::allFiles(base_path('routes')))
            ->filter(fn ($f) => in_array($f->getExtension(), ['php'], true))
            ->map(fn ($f) => File::get($f->getPathname()))
            ->implode("\n");

        foreach (["view('login')", 'view("login")', "view('welcome')", "@extends('welcome')",
            "view('transactions.create')", '<x-app-layout'] as $reference) {
            $this->assertStringNotContainsString($reference, $sources, "a removed view is still referenced: {$reference}");
        }
    }

    public function test_the_layout_the_removed_views_shared_is_still_used_by_live_pages(): void
    {
        // layouts.app is NOT dead — admin login, the password pages and contact
        // still extend it. Guards against deleting it in a later tidy-up.
        $this->assertTrue(File::exists(base_path('resources/views/layouts/app.blade.php')));

        $this->get('/admin/login')->assertOk();
        $this->get('/contact')->assertOk();
        $this->get('/admin/forgot-password')->assertOk();
    }
}
