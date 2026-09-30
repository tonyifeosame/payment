<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Link-preview metadata on the public school payment page (/pay/{school} and the
 * legacy /s/{school}/payment). Built from the school record alone: nothing from the
 * session, flash messages, old input, fees, students or payers may reach <head>.
 * The page stays noindex, with no canonical or og:url until the domain is decided.
 */
class PaymentPageMetadataTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const DESCRIPTION = 'Pay %s school fees online with FEYRA. Checkout is handled securely by Paystack and a receipt is emailed after payment.';

    private const TAGS = [
        'description', 'og:type', 'og:site_name', 'og:title', 'og:description', 'og:image',
        'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image',
    ];

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->makeSchool('Alpha School', 'alpha');
    }

    public function test_the_payment_page_carries_one_of_each_tag_built_from_the_school_name(): void
    {
        $tags = $this->metaTags($this->headOf($this->get('/pay/alpha')->assertOk()));

        $this->assertEqualsCanonicalizing(self::TAGS, array_keys($tags));
        foreach ($tags as $key => $values) {
            $this->assertCount(1, $values, "{$key} appears more than once");
        }

        $title = 'Alpha School — School fees payment';
        $description = sprintf(self::DESCRIPTION, 'Alpha School');

        $this->assertSame($description, $tags['description'][0]);
        $this->assertSame('website', $tags['og:type'][0]);
        $this->assertSame('FEYRA', $tags['og:site_name'][0]);
        $this->assertSame($title, $tags['og:title'][0]);
        $this->assertSame($description, $tags['og:description'][0]);
        $this->assertSame('summary_large_image', $tags['twitter:card'][0]);
        $this->assertSame($title, $tags['twitter:title'][0]);
        $this->assertSame($description, $tags['twitter:description'][0]);
        $this->assertSame(asset('images/feyra-og.png'), $tags['og:image'][0]);
        $this->assertSame($tags['og:image'][0], $tags['twitter:image'][0]);
        $this->assertFileExists(public_path('images/feyra-og.png'));
    }

    public function test_the_legacy_payment_url_carries_the_same_metadata(): void
    {
        $canonical = $this->metaTags($this->headOf($this->get('/pay/alpha')->assertOk()));
        $legacy = $this->metaTags($this->headOf($this->get('/s/alpha/payment')->assertOk()));

        $this->assertSame($canonical, $legacy);
    }

    public function test_the_school_name_is_escaped_exactly_once(): void
    {
        $this->makeSchool("St. Mary's & Joseph", 'st-marys');

        $head = $this->headOf($this->get('/pay/st-marys')->assertOk());

        $this->assertStringContainsString('<title>St. Mary&#039;s &amp; Joseph — School fees payment</title>', $head);
        $this->assertStringContainsString('<meta property="og:title" content="St. Mary&#039;s &amp; Joseph — School fees payment">', $head);
        $this->assertStringNotContainsString('&amp;amp;', $head);
        $this->assertStringNotContainsString('&amp;#039;', $head);

        $tags = $this->metaTags($head);
        $this->assertSame("St. Mary's & Joseph — School fees payment", $tags['twitter:title'][0]);
        $this->assertSame(sprintf(self::DESCRIPTION, "St. Mary's & Joseph"), $tags['og:description'][0]);
    }

    public function test_the_image_url_follows_the_requesting_host(): void
    {
        $tags = $this->metaTags($this->headOf($this->get('https://feyra.example/pay/alpha')->assertOk()));

        $this->assertSame('https://feyra.example/images/feyra-og.png', $tags['og:image'][0]);
        $this->assertSame('https://feyra.example/images/feyra-og.png', $tags['twitter:image'][0]);
    }

    public function test_the_page_stays_noindex_with_no_canonical_or_url_tags(): void
    {
        foreach (['/pay/alpha', '/s/alpha/payment'] as $path) {
            $head = $this->headOf($this->get($path)->assertOk());

            $this->assertSame(1, substr_count($head, '<meta name="robots" content="noindex">'), $path);
            $this->assertStringNotContainsString('rel="canonical"', $head, $path);
            $this->assertStringNotContainsString('og:url', $head, $path);
            $this->assertStringNotContainsString('twitter:url', $head, $path);
        }
    }

    public function test_a_failed_submission_does_not_leak_student_or_payer_input_into_head(): void
    {
        $session = $this->makeSessionWithTerms($this->school);
        $term = $session->terms()->first();
        $fee = $this->makeFee($this->school, 'School Fees', 'Tuition', 50000, $term->id);
        $this->makeStudent($this->school, 'ADM/001', 'Ada Obi'); // a roster: the student step renders

        // Invalid email: validation fails before any checkout, and the form is
        // re-rendered with the payer's input.
        $response = $this->from('/pay/alpha')->followingRedirects()->post('/pay/alpha/initialize', [
            'email' => 'not-an-email',
            'name' => 'Payer Secretname',
            'student_name' => 'Secret Student',
            'student_admission_number' => 'SECRET/077',
            'student_id' => 999,
            'category_id' => $fee->category_id,
            'subcategory_id' => $fee->id,
            'quantity' => 1,
            'academic_session_id' => $session->id,
            'academic_term_id' => $term->id,
        ])->assertOk();

        $html = $response->getContent();
        $head = $this->headOf($response);

        foreach (['Secret Student', 'SECRET/077', 'Payer Secretname', 'not-an-email'] as $value) {
            $this->assertStringContainsString($value, $html, "the form re-renders {$value} in the body");
            $this->assertStringNotContainsString($value, $head, "{$value} leaked into <head>");
        }
        $this->assertSame($this->metaTags($this->headOf($this->get('/pay/alpha'))), $this->metaTags($head), 'metadata is unchanged by the failed submit');
    }

    public function test_a_success_session_does_not_leak_transaction_or_receipt_data_into_head(): void
    {
        $transaction = $this->makeSuccessfulTransaction($this->school, ['reference' => 'txref-secret-4242', 'amount' => 98765.43]);

        // The success state exactly as the callback leaves it when it grants the receipt.
        $response = $this->withSession([
            'success' => 'A receipt has been sent to your email. You can also view or download it below.',
            'receipt_available' => true,
            'last_transaction_id' => $transaction->id,
        ])->get('/pay/alpha')->assertOk();

        $html = $response->getContent();
        $head = $this->headOf($response);
        $receiptUrl = route('payment.receipt', $transaction->id);

        $this->assertStringContainsString($receiptUrl, $html, 'the success state renders the receipt link in the body');
        foreach ([$receiptUrl, '/payment/receipt', 'Payment successful', 'txref-secret-4242', '98765', '98,765'] as $value) {
            $this->assertStringNotContainsString($value, $head, "{$value} leaked into <head>");
        }
        $this->assertSame($this->metaTags($this->headOf($this->get('/pay/alpha'))), $this->metaTags($head), 'metadata is unchanged by the success state');
    }

    public function test_fee_names_and_prices_stay_out_of_head(): void
    {
        $session = $this->makeSessionWithTerms($this->school);
        $this->makeFee($this->school, 'Special Levies', 'Laboratory Secretfee', 123456, $session->terms()->first()->id);

        $response = $this->get('/pay/alpha')->assertOk();
        $head = $this->headOf($response);

        $this->assertStringContainsString('Laboratory Secretfee', $response->getContent(), 'the fee is on the page');
        foreach (['Laboratory Secretfee', 'Special Levies', '123456', '123,456', 'Service fee', '2.5'] as $value) {
            $this->assertStringNotContainsString($value, $head, "{$value} leaked into <head>");
        }
    }

    public function test_an_unknown_school_is_the_branded_404_without_share_metadata(): void
    {
        $response = $this->get('/pay/no-such-school')->assertNotFound();
        $html = $response->getContent();

        $this->assertStringContainsString('Page not found', $html);
        $this->assertStringNotContainsString('property="og:', $html);
        $this->assertStringNotContainsString('name="twitter:', $html);
        $this->assertStringNotContainsString('School fees payment', $html);

        $this->get('/s/no-such-school/payment')->assertNotFound();
    }

    private function headOf($response): string
    {
        $html = $response->getContent();
        $this->assertSame(1, preg_match('#<head>(.*?)</head>#s', $html, $m), 'the page has one <head>');

        return $m[1];
    }

    /**
     * The description plus every og:* / twitter:* meta tag, decoded, grouped by key.
     *
     * @return array<string, list<string>>
     */
    private function metaTags(string $head): array
    {
        preg_match_all('/<meta\s+(?:property|name)="(description|(?:og|twitter):[^"]+)"\s+content="([^"]*)"\s*>/u', $head, $matches, PREG_SET_ORDER);

        $tags = [];
        foreach ($matches as [, $key, $content]) {
            $tags[$key][] = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $tags;
    }
}
