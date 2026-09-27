<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contact, sign-in and password-reset pages on the FEYRA marketing layout: titles,
 * submit-once buttons, and the server messages each page must show. Controller
 * behaviour is covered by the login, throttle, password-reset and contact tests.
 */
class PublicAuthPagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, ?string}> path, title, meta description (null: layout default) */
    public static function pages(): array
    {
        return [
            'contact' => ['/contact', 'Contact us — FEYRA', 'Questions about collecting school fees with FEYRA? Send us a message or reach us on WhatsApp.'],
            'login' => ['/admin/login', 'Sign in — FEYRA', 'Sign in to your school&#039;s FEYRA account to manage fees, students, payments and payouts.'],
            'forgot password' => ['/admin/forgot-password', 'Forgot password — FEYRA', 'Request a link to reset the password for your school&#039;s FEYRA account.'],
            'reset password' => ['/admin/reset-password/some-token?email=alpha%40example.test', 'Reset password — FEYRA', null],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_uses_the_feyra_layout_with_its_own_title(string $path, string $title, ?string $description): void
    {
        $html = $this->get($path)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*'.preg_quote($title, '/').'\s*<\/title>/u', $html);
        $this->assertStringContainsString('<link rel="icon" type="image/svg+xml"', $html);
        $this->assertStringNotContainsString('font-awesome', $html); // the old layouts/app asset
        $this->assertStringNotContainsString('bg-blue-600', $html);   // the old layouts/app navbar

        if ($description !== null) {
            // The layout pads the attribute with a space either side.
            $this->assertStringContainsString('<meta name="description" content=" '.$description.' ">', $html);
        }
    }

    #[DataProvider('pages')]
    public function test_form_submits_once_and_keeps_its_csrf_token(string $path): void
    {
        $html = $this->get($path)->getContent();

        $this->assertSame(1, preg_match_all('/<form\b[^>]*\sdata-submit-once[\s>]/', $html));
        $this->assertSame(1, substr_count($html, '<button type="submit" class="btn-obsidian w-full" data-submit>'));
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertSame(1, substr_count($html, "form.querySelector('[data-submit]')"), 'the submit-once script is included exactly once');
    }

    public function test_login_shows_validation_errors_and_the_password_reset_confirmation(): void
    {
        $this->from('/admin/login')->followingRedirects()->post('/admin/login', ['name' => '', 'password' => ''])
            ->assertOk()
            ->assertSee('id="name-error"', false)
            ->assertSee('id="password-error"', false);

        // SchoolAuthController::reset redirects here with this status.
        $this->withSession(['status' => 'Your password has been reset successfully.'])->get('/admin/login')
            ->assertSee('Your password has been reset successfully.');
    }

    public function test_forgot_password_shows_an_invalid_email_error(): void
    {
        $this->from('/admin/forgot-password')->followingRedirects()->post('/admin/forgot-password', ['email' => 'not-an-email'])
            ->assertOk()
            ->assertSee('id="email-error"', false);
    }

    public function test_reset_form_keeps_the_token_and_prefills_the_email(): void
    {
        $this->get('/admin/reset-password/some-token?email=alpha%40example.test')
            ->assertSee('<input type="hidden" name="token" value="some-token">', false)
            ->assertSee('value="alpha@example.test"', false);
    }

    public function test_contact_page_lists_only_the_whatsapp_channel(): void
    {
        $html = $this->get('/contact')->getContent();

        $this->assertStringContainsString('href="https://wa.me/2348143369102"', $html);
        $this->assertStringNotContainsString('instagram', strtolower($html));
        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('tel:', $html);
    }
}
