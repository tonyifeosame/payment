<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L8 — the public payment page's student lookup reveals a student only to someone
 * who already knows BOTH the student's full name and complete admission number.
 *
 * It used to be a fuzzy search: two characters (or a LIKE wildcard) listed up to
 * ten students' names and classes, a sweep of short queries listed the whole
 * active roster, admission numbers matched on any fragment (so the masked number
 * could be rebuilt one character at a time), and a failed submit echoed back the
 * details of any student_id posted to it. Every one of those paths is pinned
 * shut here, and every failure must look exactly the same.
 */
class StudentLookupPrivacyTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const NOT_FOUND = ['student' => null];

    private School $alpha;

    private AcademicTerm $term;

    private Subcategory $fee;

    private Student $ada;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret']);
        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/l8']])]);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->term = $this->makeSessionWithTerms($this->alpha, '2026/2027')->terms()->firstOrFail();
        $this->fee = $this->makeFee($this->alpha, 'School Fees', 'Tuition', 50000, $this->term->id);

        $this->ada = $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo', 'JSS 1');
        foreach (['Bola Ade', 'Chinedu Eze', 'Dayo Bello', 'Emeka Obi', 'Funke Ajayi'] as $i => $name) {
            $this->makeStudent($this->alpha, 'A/2026/00'.($i + 2), $name, 'JSS 2');
        }
    }

    private function lookup(mixed $name, mixed $admissionNumber, string $prefix = '/pay/alpha'): TestResponse
    {
        return $this->postJson("{$prefix}/student-search", ['name' => $name, 'admission_number' => $admissionNumber]);
    }

    private function assertNotFound(TestResponse $response, string $label = ''): void
    {
        $response->assertOk();
        $this->assertSame(self::NOT_FOUND, $response->json(), $label);
    }

    // --------------------------------------------------------------- success

    public function test_the_full_name_and_complete_admission_number_reveal_the_student(): void
    {
        foreach (['/pay/alpha', '/s/alpha/payment'] as $prefix) {
            $this->lookup('Adaeze Okonkwo', 'A/2026/001', $prefix)
                ->assertOk()
                ->assertExactJson(['student' => [
                    'id' => $this->ada->id,
                    'full_name' => 'Adaeze Okonkwo',
                    'class_name' => 'JSS 1',
                    'admission_number_masked' => '*/****/001',
                ]]);
        }
    }

    public function test_case_and_whitespace_are_normalised(): void
    {
        foreach ([
            ['adaeze okonkwo', 'a/2026/001'],
            ['ADAEZE OKONKWO', 'A/2026/001'],
            ['  Adaeze   Okonkwo  ', '  a/2026/001 '],
            ["Adaeze\tOkonkwo", 'A/2026/001'],
        ] as [$name, $number]) {
            $this->lookup($name, $number)->assertOk()->assertJsonPath('student.id', $this->ada->id);
        }
    }

    // ---------------------------------------------------- nothing before both

    public function test_a_name_alone_or_a_number_alone_reveals_nothing(): void
    {
        $this->assertNotFound($this->lookup('Adaeze Okonkwo', ''), 'name, empty number');
        $this->assertNotFound($this->lookup('Adaeze Okonkwo', null), 'name, no number');
        $this->assertNotFound($this->postJson('/pay/alpha/student-search', ['name' => 'Adaeze Okonkwo']), 'name only');
        $this->assertNotFound($this->lookup('', 'A/2026/001'), 'number, empty name');
        $this->assertNotFound($this->postJson('/pay/alpha/student-search', ['admission_number' => 'A/2026/001']), 'number only');
        $this->assertNotFound($this->postJson('/pay/alpha/student-search', []), 'nothing');
        // The old search parameter is simply ignored.
        $this->assertNotFound($this->postJson('/pay/alpha/student-search', ['q' => 'ad']), 'legacy q');
    }

    public function test_a_partial_admission_number_reveals_nothing(): void
    {
        foreach (['A/2026/00', '/2026/001', '2026', '001', 'A/2026/0011', 'A2026001'] as $partial) {
            $this->assertNotFound($this->lookup('Adaeze Okonkwo', $partial), $partial);
        }
    }

    public function test_part_of_the_name_reveals_nothing(): void
    {
        // Full name required: not the surname, not the first name, not a fragment,
        // not extra words, not the words in another order.
        foreach (['Okonkwo', 'Adaeze', 'Adaeze Okon', 'Ada Okonkwo', 'Adaeze Okonkwo Jr', 'Okonkwo Adaeze'] as $name) {
            $this->assertNotFound($this->lookup($name, 'A/2026/001'), $name);
        }
    }

    public function test_wildcards_reveal_nothing(): void
    {
        foreach ([['%', '%'], ['_', '_'], ['%%', '%%'], ['__', '__'], ['Adaeze Okonkwo', 'A/2026/%'], ['Adaeze Okonkwo', 'A/2026/00_'],
            ['Adaeze%', 'A/2026/001'], ['%Okonkwo', 'A/2026/001'], ['Adaeze Okonkw_', 'A/2026/001'], ['*', '*']] as [$name, $number]) {
            $this->assertNotFound($this->lookup($name, $number), "{$name} / {$number}");
        }
    }

    public function test_a_mismatched_name_and_number_reveal_nothing(): void
    {
        // Two real students, crossed.
        $this->assertNotFound($this->lookup('Bola Ade', 'A/2026/001'));
        $this->assertNotFound($this->lookup('Adaeze Okonkwo', 'A/2026/002'));
        // A real name with an unknown number, and the reverse.
        $this->assertNotFound($this->lookup('Adaeze Okonkwo', 'A/2026/999'));
        $this->assertNotFound($this->lookup('Nobody Known', 'A/2026/001'));
    }

    public function test_inactive_and_other_school_students_reveal_nothing(): void
    {
        $this->makeStudent($this->alpha, 'A/2026/090', 'Grace Okonkwo', 'SS 3', ['status' => Student::STATUS_GRADUATED]);
        $this->makeStudent($this->alpha, 'A/2026/091', 'Lola Okonkwo', 'JSS 2', ['status' => Student::STATUS_LEFT]);
        $beta = $this->makeSchool('Beta School', 'beta');
        $this->makeStudent($beta, 'B/001', 'Beta Kid', 'SS 1');

        $this->assertNotFound($this->lookup('Grace Okonkwo', 'A/2026/090'));
        $this->assertNotFound($this->lookup('Lola Okonkwo', 'A/2026/091'));
        $this->assertNotFound($this->lookup('Beta Kid', 'B/001'));
        $this->lookup('Beta Kid', 'B/001', '/pay/beta')->assertJsonPath('student.full_name', 'Beta Kid');
        $this->assertNotFound($this->lookup('Adaeze Okonkwo', 'A/2026/001', '/pay/beta'));
    }

    public function test_malformed_input_gets_the_same_generic_result(): void
    {
        $this->assertNotFound($this->lookup(['Adaeze Okonkwo'], 'A/2026/001'), 'array name');
        $this->assertNotFound($this->lookup('Adaeze Okonkwo', ['A/2026/001']), 'array number');
        $this->assertNotFound($this->lookup(str_repeat('a', 256), 'A/2026/001'), 'long name');
        $this->assertNotFound($this->lookup('Adaeze Okonkwo', str_repeat('1', 51)), 'long number');
        $this->assertNotFound($this->lookup(12345, 67890), 'numbers');
    }

    public function test_every_failure_is_byte_identical(): void
    {
        $bodies = collect([
            $this->lookup('Okonkwo', 'A/2026/001'),        // part of the name
            $this->lookup('Adaeze Okonkwo', '001'),         // part of the number
            $this->lookup('Bola Ade', 'A/2026/001'),        // crossed
            $this->lookup('%', '%'),                        // wildcard
            $this->postJson('/pay/alpha/student-search', ['name' => 'Adaeze Okonkwo']), // name only
            $this->lookup('Nobody', 'X/1'),                 // unknown
        ])->map(fn (TestResponse $r) => $r->status().' '.$r->getContent())->unique();

        $this->assertCount(1, $bodies);
        $this->assertSame('200 {"student":null}', $bodies->first());
    }

    public function test_the_old_two_letter_sweep_now_finds_no_one(): void
    {
        $found = 0;
        foreach (['ad', 'ol', 'ok', 'ch', 'em', 'a/', '20', '00', '__', '%%'] as $q) {
            $found += (int) ($this->lookup($q, $q)->json('student') !== null);
            $found += (int) ($this->lookup($q, 'A/2026/001')->json('student') !== null);
        }

        $this->assertSame(0, $found);
    }

    public function test_the_lookup_is_post_only_so_details_stay_out_of_urls(): void
    {
        $this->getJson('/pay/alpha/student-search?name=Adaeze%20Okonkwo&admission_number=A/2026/001')->assertStatus(405);
        $this->getJson('/s/alpha/payment/student-search?q=ad')->assertStatus(405);
    }

    public function test_the_existing_rate_limit_still_applies(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->lookup('Nobody '.$i, 'X/'.$i)->assertOk();
        }

        $this->lookup('Adaeze Okonkwo', 'A/2026/001')->assertStatus(429);
    }

    // --------------------------------------------------- failed-submit reload

    private function failedSubmit(array $fields): void
    {
        // Missing email and fee: a validation failure that round-trips old input.
        $this->from('/pay/alpha')->post('/pay/alpha/initialize', $fields)->assertRedirect('/pay/alpha');
    }

    public function test_a_bare_student_id_is_never_echoed_back(): void
    {
        $this->failedSubmit(['student_id' => $this->ada->id]);

        $this->get('/pay/alpha')->assertOk()
            ->assertSee("data-old-student='null'", false)
            ->assertSee('name="student_id" value=""', false)
            ->assertDontSee('Adaeze Okonkwo')
            ->assertDontSee('*\/****\/001', false);
    }

    public function test_a_student_id_with_a_non_matching_name_or_number_is_not_echoed_back(): void
    {
        foreach ([
            ['student_name' => 'Wrong Name', 'student_admission_number' => 'A/2026/001'],
            ['student_name' => 'Adaeze Okonkwo', 'student_admission_number' => 'A/2026/00'],
            // A correct pair for ANOTHER student cannot unlock this id.
            ['student_name' => 'Bola Ade', 'student_admission_number' => 'A/2026/002'],
        ] as $typed) {
            $this->failedSubmit(['student_id' => $this->ada->id] + $typed);

            $this->get('/pay/alpha')->assertOk()
                ->assertSee("data-old-student='null'", false)
                ->assertDontSee('"full_name":"Adaeze Okonkwo"', false)
                ->assertDontSee('*\/****\/001', false);
        }
    }

    public function test_a_genuine_failed_submit_still_reselects_the_verified_student(): void
    {
        $this->failedSubmit([
            'student_id' => $this->ada->id,
            'student_name' => 'adaeze  okonkwo',
            'student_admission_number' => 'a/2026/001',
        ]);

        $this->get('/pay/alpha')->assertOk()
            ->assertSee('name="student_id" value="'.$this->ada->id.'"', false)
            ->assertSee('"full_name":"Adaeze Okonkwo"', false)
            ->assertSee('"class_name":"JSS 1"', false)
            ->assertSee('*\/****\/001', false)
            ->assertDontSee('A\/2026\/001', false);
    }

    // ------------------------------------------------------------- the page

    public function test_the_page_asks_for_both_and_embeds_no_roster(): void
    {
        $this->get('/pay/alpha')->assertOk()
            ->assertSee('name="student_name"', false)
            ->assertSee('name="student_admission_number"', false)
            ->assertSee('Find student')
            ->assertSee('full name and complete admission number')
            ->assertDontSee('Adaeze Okonkwo')
            ->assertDontSee('A/2026/001')
            ->assertDontSee('student_query', false);
    }

    public function test_checkout_is_unchanged_for_a_verified_student(): void
    {
        $id = $this->lookup('Adaeze Okonkwo', 'A/2026/001')->json('student.id');

        $this->post('/pay/alpha/initialize', [
            'email' => 'parent@example.test', 'category_id' => $this->fee->category_id, 'subcategory_id' => $this->fee->id, 'quantity' => 1,
            'student_id' => $id, 'student_name' => 'Adaeze Okonkwo', 'student_admission_number' => 'A/2026/001',
            'academic_session_id' => $this->term->academic_session_id, 'academic_term_id' => $this->term->id,
        ])->assertRedirect('https://checkout.paystack.com/l8');

        $this->assertDatabaseHas('transactions', [
            'school_id' => $this->alpha->id, 'student_id' => $this->ada->id,
            'student_name' => 'Adaeze Okonkwo', 'student_admission_number' => 'A/2026/001', 'student_class' => 'JSS 1',
        ]);
    }
}
