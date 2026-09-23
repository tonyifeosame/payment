<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * URL migration, stage 1: the canonical /pay/{school} namespace is an alias of the
 * legacy /s/{school}/payment routes — same controller methods, same rules — and
 * newly generated public links use it, while every legacy URL keeps working.
 */
class CanonicalPaymentRoutesTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private AcademicTerm $term;

    private Subcategory $fee;

    private Student $active;

    private Student $graduated;

    private Student $left;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
        $this->term = $this->makeSessionWithTerms($this->alpha, '2026/2027')->terms()->where('number', 1)->firstOrFail();
        $this->fee = $this->makeFee($this->alpha, 'School Fees', 'Tuition', 50000, $this->term->id);
        $this->makeFee($this->beta, 'Beta Fees', 'Beta Tuition', 999, null);

        $this->active = $this->makeStudent($this->alpha, 'A/001', 'Ada Okonkwo', 'JSS 1');
        $this->graduated = $this->makeStudent($this->alpha, 'A/002', 'Grace Okonkwo', 'SS 3', ['status' => Student::STATUS_GRADUATED]);
        $this->left = $this->makeStudent($this->alpha, 'A/003', 'Lola Okonkwo', 'JSS 2', ['status' => Student::STATUS_LEFT]);
        $this->makeStudent($this->beta, 'B/001', 'Beta Okonkwo', 'SS 1');

        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'parent@example.test', 'name' => 'Parent',
            'category_id' => $this->fee->category_id, 'subcategory_id' => $this->fee->id, 'quantity' => 1,
            'student_id' => $this->active->id,
            'academic_session_id' => $this->term->academic_session_id, 'academic_term_id' => $this->term->id,
        ], $overrides);
    }

    public function test_canonical_routes_alias_the_legacy_controller_methods_and_middleware(): void
    {
        $routes = Route::getRoutes();
        foreach ([
            ['public.payment', 'school.payment.index', 'GET', 'pay/{school}'],
            ['public.payment.initialize', 'school.payment.initialize', 'POST', 'pay/{school}/initialize'],
            ['public.payment.student-search', 'school.payment.student-search', 'POST', 'pay/{school}/student-search'],
        ] as [$new, $legacy, $method, $uri]) {
            $n = $routes->getByName($new);
            $l = $routes->getByName($legacy);
            $this->assertNotNull($n, "{$new} is registered");
            $this->assertNotNull($l, "{$legacy} is still registered");
            $this->assertSame($uri, $n->uri());
            $this->assertContains($method, $n->methods());
            // Same handler, same middleware (incl. the student-search throttle), same binding.
            $this->assertSame($l->getActionName(), $n->getActionName());
            $this->assertSame($l->gatherMiddleware(), $n->gatherMiddleware());
            $this->assertSame($l->bindingFields(), $n->bindingFields());
        }

        // Receipt/callback routes untouched.
        $this->assertSame('payment/callback', $routes->getByName('payment.callback')->uri());
        $this->assertSame('payment/receipt/{transaction}', $routes->getByName('payment.receipt')->uri());
        $this->assertSame('payment/receipt/{transaction}/download', $routes->getByName('payment.receipt.download')->uri());
        $this->assertNull($routes->getByName('public.payment.receipt'));
    }

    public function test_a_canonical_page_is_the_same_payment_page_and_the_legacy_page_still_works(): void
    {
        $new = $this->get('/pay/alpha')->assertOk();
        $legacy = $this->get('/s/alpha/payment')->assertOk();

        foreach ([$new, $legacy] as $page) {
            $page->assertSee('Alpha School')->assertSee('Tuition')->assertSee('"price":50000', false)->assertSee('admission_number', false)
                ->assertDontSee('Beta Fees')->assertDontSee('manifest.webmanifest', false);
        }
        // Each page posts and searches within its own namespace.
        // (the search URL is JSON-encoded inline, so slashes are escaped)
        $new->assertSee('action="http://localhost/pay/alpha/initialize"', false)->assertSee('pay\/alpha\/student-search', false)
            ->assertDontSee('/s/alpha/payment/initialize', false)->assertDontSee('s\/alpha\/payment\/student-search', false);
        $legacy->assertSee('action="http://localhost/s/alpha/payment/initialize"', false)->assertSee('s\/alpha\/payment\/student-search', false)
            ->assertDontSee('/pay/alpha/initialize', false)->assertDontSee('pay\/alpha\/student-search', false);

        $this->get('/pay/nope')->assertNotFound();
        $this->get('/pay/alpha/')->assertOk(); // trailing slash tolerated like any Laravel route
    }

    public function test_canonical_student_lookup_preserves_scoping_eligibility_masking_and_shape(): void
    {
        $pair = ['name' => 'Ada Okonkwo', 'admission_number' => 'A/001'];
        $new = $this->postJson('/pay/alpha/student-search', $pair)->assertOk();
        $legacy = $this->postJson('/s/alpha/payment/student-search', $pair)->assertOk();
        $this->assertSame($legacy->json(), $new->json());

        // The verified active student, masked admission number, only these keys.
        $new->assertJsonPath('student.full_name', 'Ada Okonkwo');
        $this->assertSame(['id', 'full_name', 'class_name', 'admission_number_masked'], array_keys($new->json('student')));
        $this->assertStringNotContainsString('A/001', $new->getContent());
        $this->assertSame($this->active->maskedAdmissionNumber(), $new->json('student.admission_number_masked'));
        $this->assertNotSame('A/001', $new->json('student.admission_number_masked'));

        // Inactive students, other-school isolation, a name alone, unknown school.
        $this->postJson('/pay/alpha/student-search', ['name' => 'Grace Okonkwo', 'admission_number' => 'A/002'])->assertOk()->assertExactJson(['student' => null]);
        $this->postJson('/pay/alpha/student-search', ['name' => 'Beta Okonkwo', 'admission_number' => 'B/001'])->assertOk()->assertExactJson(['student' => null]);
        $this->postJson('/pay/beta/student-search', ['name' => 'Beta Okonkwo', 'admission_number' => 'B/001'])->assertOk()->assertJsonPath('student.full_name', 'Beta Okonkwo');
        $this->postJson('/pay/alpha/student-search', ['name' => 'Ada Okonkwo'])->assertOk()->assertExactJson(['student' => null]);
        $this->postJson('/pay/nope/student-search', $pair)->assertNotFound();
    }

    public function test_canonical_initialize_reaches_the_same_checkout_and_enforces_the_same_rules(): void
    {
        $this->post('/pay/alpha/initialize', $this->payload())->assertRedirect('https://checkout.paystack.com/abc');
        $t = Transaction::sole();
        $this->assertSame($this->alpha->id, (int) $t->school_id);
        $this->assertSame($this->active->id, (int) $t->student_id);
        $this->assertEquals(50000.00, (float) $t->fee_amount);
        $this->assertEquals(51250.00, (float) $t->amount); // server-side amount + markup, as on the legacy route
        Http::assertSentCount(1);

        // Same server-side authority as the legacy route: browser amounts are ignored.
        $this->post('/pay/alpha/initialize', $this->payload(['amount' => '1', 'price' => '1']))->assertRedirect();
        $this->assertEquals(51250.00, (float) Transaction::latest('id')->first()->amount);

        // Inactive students are refused exactly as on the legacy route.
        $this->post('/pay/alpha/initialize', $this->payload(['student_id' => $this->graduated->id]))->assertNotFound();
        $this->post('/pay/alpha/initialize', $this->payload(['student_id' => $this->left->id]))->assertNotFound();
        $this->post('/s/alpha/payment/initialize', $this->payload(['student_id' => $this->graduated->id]))->assertNotFound();
        $this->assertSame(2, Transaction::count());

        // Validation and CSRF behave the same (validation errors redirect back).
        $this->from('/pay/alpha')->post('/pay/alpha/initialize', $this->payload(['email' => 'nope']))
            ->assertRedirect('/pay/alpha')->assertSessionHasErrors('email');
        $this->post('/pay/nope/initialize', $this->payload())->assertNotFound();
    }

    public function test_a_school_cannot_reach_another_schools_data_through_pay(): void
    {
        // Beta's page shows beta's fees only; alpha's student and fee ids fail closed on beta's URL.
        $this->get('/pay/beta')->assertOk()->assertSee('Beta Tuition')->assertDontSee('"name":"Tuition"', false)->assertDontSee('Ada Okonkwo');
        $this->post('/pay/beta/initialize', $this->payload())->assertNotFound();
        $betaFee = Subcategory::where('school_id', $this->beta->id)->first();
        $this->post('/pay/beta/initialize', ['email' => 'p@example.test', 'category_id' => $betaFee->category_id, 'subcategory_id' => $betaFee->id, 'quantity' => 1, 'student_id' => $this->active->id])->assertNotFound();
        $this->assertDatabaseCount('transactions', 0);

        // An admin session for alpha changes nothing about beta's public page.
        $this->actingAsSchoolAdmin($this->alpha)->get('/pay/beta')->assertOk()->assertDontSee('Ada Okonkwo');
        $this->actingAsSchoolAdmin($this->alpha)->postJson('/pay/beta/student-search', ['name' => 'Beta Okonkwo', 'admission_number' => 'B/001'])->assertOk()->assertJsonPath('student.full_name', 'Beta Okonkwo');
        $this->actingAsSchoolAdmin($this->alpha)->postJson('/pay/beta/student-search', ['name' => 'Ada Okonkwo', 'admission_number' => 'A/001'])->assertOk()->assertExactJson(['student' => null]);
    }

    public function test_new_public_links_use_the_canonical_url_and_legacy_links_are_kept(): void
    {
        $this->assertSame('http://localhost/pay/alpha', $this->alpha->paymentUrl());

        $share = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/share')->assertOk();
        $share->assertSee('http://localhost/pay/alpha')->assertSee(rawurlencode('http://localhost/pay/alpha'), false)->assertDontSee('/s/alpha/payment', false);
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard')->assertOk()->assertSee('http://localhost/pay/alpha')->assertDontSee('/s/alpha/payment"', false);
        $this->get('/')->assertOk()->assertSee("'/pay/' + encodeURIComponent(slug)", false)->assertDontSee("'/s/' + encodeURIComponent(slug) + '/payment'", false);

        // Receipt: same URL as before; its "back" link now points at the canonical page.
        $t = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-1']);
        $this->withSession(['last_transaction_id' => $t->id])->get("/payment/receipt/{$t->id}")->assertOk()->assertSee('http://localhost/pay/alpha');

        // Admin pages (canonical since stage 2; the legacy twins are covered in AdminNamespaceTest).
        foreach (['/admin/alpha/dashboard', '/admin/alpha/students', '/admin/alpha/transactions', '/admin/alpha/payouts', '/admin/alpha/settings'] as $url) {
            $this->actingAsSchoolAdmin($this->alpha)->get($url)->assertOk();
        }

        // The admin app scope never contains the public payment namespace.
        $manifest = json_decode($this->get('/admin/manifest.webmanifest')->assertOk()->getContent(), true);
        $this->assertSame('/admin/', $manifest['scope']);
        $this->assertSame('/admin/', $manifest['start_url']);
        $this->assertTrue(str_starts_with($manifest['start_url'], $manifest['scope']));
        $this->assertFalse(str_starts_with('/pay/alpha', $manifest['scope']));
    }
}
