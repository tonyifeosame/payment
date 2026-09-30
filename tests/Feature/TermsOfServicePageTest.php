<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public Terms of Service at /terms: on the marketing layout, indexable, not a
 * share card, linked from every public footer, stating only what the product does
 * today, and free of placeholders and claims it cannot support.
 */
class TermsOfServicePageTest extends TestCase
{
    use RefreshDatabase;

    private const HEADINGS = [
        '1. About FEYRA and These Terms',
        '2. Who These Terms Apply To',
        '3. School Accounts',
        '4. School Information and Student Records',
        '5. School Fees and Payment Pages',
        '6. Payments Through Paystack',
        '7. Service Fee',
        '8. Payouts to Schools',
        '9. Payment Concerns and Refund Requests',
        '10. Receipts and Records',
        '11. Acceptable Use',
        '12. Closing a School Account',
        '13. Intellectual Property',
        '14. Third-Party Services',
        '15. Availability and Changes to the Service',
        '16. Changes to These Terms',
        '17. Contact Us',
    ];

    public function test_terms_page_is_public_on_the_marketing_layout_and_indexable(): void
    {
        $this->assertSame(url('/terms'), route('terms.show'));

        $html = $this->get('/terms')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<title>\s*Terms of Service — FEYRA\s*<\/title>/u', $html);
        $this->assertStringContainsString('<meta name="description" content=" The terms for using FEYRA to collect school fees online: school accounts, payment pages, payments through Paystack, the service fee, payouts and receipts. ">', $html);
        $this->assertStringContainsString('href="'.route('admin.login').'"', $html); // slim header
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringNotContainsString('property="og:', $html);
        $this->assertStringNotContainsString('name="twitter:', $html);
    }

    public function test_every_section_is_present_in_order_with_a_working_contents_link(): void
    {
        $response = $this->get('/terms')->assertSeeInOrder(self::HEADINGS);
        $html = $response->getContent();

        // Every in-page link lands on an element that exists, and each of the 17
        // sections has a contents link (plus the layout's own #main skip link).
        preg_match_all('/href="#([a-z-]+)"/', $html, $links);
        $targets = array_unique($links[1]);
        foreach ($targets as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "#{$id} has no target");
        }
        $this->assertCount(count(self::HEADINGS), array_diff($targets, ['main']));
    }

    public function test_it_links_to_the_privacy_policy_and_the_support_email(): void
    {
        $this->get('/terms')
            ->assertSee('<a href="'.route('privacy.show').'">Privacy Policy</a>', false)
            ->assertSee('href="mailto:ifeosamenkem@gmail.com"', false);
    }

    public function test_it_states_what_the_product_does_today(): void
    {
        $this->get('/terms')
            ->assertSee('Schools set their own school fees.')
            ->assertSee('hosted checkout')
            ->assertSee('FEYRA confirms each payment with Paystack')
            ->assertSee('still pending after 24 hours')
            ->assertSee('The service fee is currently 2.5%')
            ->assertSee('shows the fee amount, the service fee and the total before the payer continues to Paystack')
            ->assertSee('verified bank account')
            ->assertSee('FEYRA cannot promise when a transfer will arrive.')
            ->assertSee('manual review or action by FEYRA')
            ->assertSee('FEYRA does not currently provide an automated refund feature.')
            ->assertSee('FEYRA does not currently prevent the same fee from being paid more than once.')
            ->assertSee('Receipt links do not currently expire automatically')
            ->assertSee('does not currently offer self-service closure or deletion of school accounts');
    }

    public function test_the_stated_service_fee_matches_the_configured_markup(): void
    {
        // The page states the rate as text. If the configured rate changes, this
        // fails until the Terms are updated to match.
        $this->assertSame(2.5, (float) config('fees.markup_percent'));
    }

    public function test_it_carries_no_placeholders_review_notes_or_unsupported_claims(): void
    {
        $html = $this->get('/terms')->getContent();
        preg_match('/<main\b[^>]*>(.*)<\/main>/s', $html, $main);
        $text = html_entity_decode(strip_tags($main[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ([
            '[', ']', 'TODO', 'Review:', 'lorem', 'example.com', 'Effective date',
            '100% secure', 'guarantee', 'certified', 'licensed', 'regulated', 'compliant',
            'zero risk', 'instant', 'automatic refund', 'refundable',
            'liability', 'indemn', 'governing law', 'jurisdiction', 'arbitration',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $text, "found \"{$forbidden}\"");
        }
    }

    public function test_the_main_footer_links_to_the_terms(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<a href="'.route('terms.show').'" class="text-brand-slate hover:text-brand-obsidian">Terms of Service</a>', false);
    }

    public function test_the_slim_and_registration_footers_link_to_the_terms(): void
    {
        foreach (['/contact' => 200, '/admin/login' => 200, '/registration/create' => 200, '/privacy' => 200, '/terms' => 200, '/no-such-page-anywhere' => 404] as $path => $status) {
            $this->get($path)
                ->assertStatus($status)
                ->assertSee('<a href="'.route('terms.show').'" class="hover:text-brand-obsidian">Terms</a>', false);
        }
    }
}
