<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public Privacy Policy at /privacy: on the marketing layout, indexable, not a
 * share card, linked from both footers, and free of internal review notes,
 * placeholders and claims the implementation does not support.
 */
class PrivacyPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = [
        '1. Who We Are',
        '2. Who This Policy Covers',
        '3. Information We Collect',
        '4. How We Use Information',
        '5. Payments and Paystack',
        '6. How We Share Information',
        '7. Cookies and Similar Technologies',
        '8. Data Security',
        '9. Data Retention',
        '10. Data Deletion and Your Requests',
        '11. Student and Children’s Information',
        '12. International and Third-Party Services',
        '13. Changes to This Privacy Policy',
        '14. Contact Us',
    ];

    public function test_privacy_page_is_public_on_the_marketing_layout_and_indexable(): void
    {
        $this->assertSame(url('/privacy'), route('privacy.show'));

        $html = $this->get('/privacy')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*Privacy Policy — FEYRA\s*<\/title>/u', $html);
        $this->assertStringContainsString('<meta name="description" content=" How FEYRA collects, uses, shares and protects information', $html);
        $this->assertStringContainsString('href="'.route('admin.login').'"', $html); // slim header
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringNotContainsString('property="og:', $html);
        $this->assertStringNotContainsString('name="twitter:', $html);
    }

    public function test_every_section_is_present_in_order_with_a_working_contents_link(): void
    {
        $response = $this->get('/privacy')->assertSeeInOrder(self::HEADINGS);
        $html = $response->getContent();

        // Every in-page link lands on an element that exists, and each of the 14
        // sections has a contents link (plus the layout's own #main skip link).
        preg_match_all('/href="#([a-z-]+)"/', $html, $links);
        $targets = array_unique($links[1]);
        foreach ($targets as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "#{$id} has no target");
        }
        $this->assertCount(count(self::HEADINGS), array_diff($targets, ['main']));

        $response->assertSee('href="mailto:ifeosamenkem@gmail.com"', false);
    }

    public function test_it_states_the_payment_cookie_and_data_use_facts(): void
    {
        $this->get('/privacy')
            ->assertSee('Paystack')
            ->assertSee('FEYRA does not receive or store card details.')
            ->assertSee('Session cookie')
            ->assertSee('XSRF-TOKEN')
            ->assertSee('school_remember')
            ->assertSee('does <strong>not</strong> use analytics, advertising or preference cookies', false)
            ->assertSee('We do <strong>not</strong> sell personal information.', false)
            ->assertSee('Receipt links currently do <strong>not</strong> expire automatically.', false)
            ->assertSee('does not currently offer self-service deletion');
    }

    public function test_it_carries_no_review_notes_placeholders_or_unsupported_claims(): void
    {
        $html = $this->get('/privacy')->getContent();
        preg_match('/<main\b[^>]*>(.*)<\/main>/s', $html, $main);
        $text = html_entity_decode(strip_tags($main[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (['[', ']', 'TODO', 'Review:', 'lorem', 'example.com', 'Effective date', '100%', 'fully secure', 'compliant', 'certified', 'encrypted at rest', 'Data Protection Officer'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $text, "found \"{$forbidden}\"");
        }
    }

    public function test_the_main_footer_links_to_the_privacy_policy(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<a href="'.route('privacy.show').'" class="text-brand-slate hover:text-brand-obsidian">Privacy Policy</a>', false);
    }

    public function test_the_slim_footer_links_to_the_privacy_policy(): void
    {
        foreach (['/contact' => 200, '/admin/login' => 200, '/registration/create' => 200, '/privacy' => 200, '/no-such-page-anywhere' => 404] as $path => $status) {
            $this->get($path)
                ->assertStatus($status)
                ->assertSee('<a href="'.route('privacy.show').'" class="hover:text-brand-obsidian">Privacy</a>', false);
        }
    }
}
