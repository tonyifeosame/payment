<?php

namespace Tests\Feature;

use App\Mail\SchoolLinksMail;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * N1 — a school name with no sluggable characters ("###", emoji, CJK) is a valid
 * name, but Str::slug() reduces it to ''. The slug is the route key, so an empty
 * one made every URL for the school impossible to generate: registration saved
 * the row, signed the admin in and then crashed building the dashboard URL, and
 * the school could never log in or take a payment.
 *
 * Such names now start from the fallback base `school` (then `school-1`, …), an
 * empty slug is never treated as available, and a data migration repairs any
 * row already stored with one.
 */
class EmptySlugTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_27_000000_repair_empty_school_slugs.php';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        config(['services.paystack.secret_key' => 'sk_test_fake']);
    }

    private function register(string $name, string $email): TestResponse
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'RESOLVED LTD', 'account_number' => '0123456789'],
        ], 200)]);

        return $this->post('/registration', [
            'name' => $name,
            'email' => $email,
            'account_number' => '0123456789',
            'bank' => 'Guaranty Trust Bank',
            'bank_code' => '058',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ]);
    }

    /** Not School::sole(): an early migration seeds a "Default School" row. */
    private function registered(string $name): School
    {
        return School::where('name', $name)->sole();
    }

    private function runRepair(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    // --------------------------------------------------------- slug minting

    public function test_a_symbol_only_name_gets_the_fallback_slug(): void
    {
        $this->assertSame('school', School::availableSlugFor('###'));
    }

    public function test_subsequent_symbol_only_names_walk_the_existing_suffixes(): void
    {
        $this->makeSchool('###', School::availableSlugFor('###'));
        $this->assertSame('school-1', School::availableSlugFor('!!!'));

        $this->makeSchool('!!!', 'school-1');
        $this->assertSame('school-2', School::availableSlugFor('...'));
    }

    public function test_emoji_and_cjk_names_get_the_fallback_slug(): void
    {
        foreach (['🎓🎓', '学校', '---', '___'] as $name) {
            $this->assertSame('school', School::availableSlugFor($name), json_encode($name));
        }

        // Scripts Str::slug can transliterate keep their real slug.
        $this->assertSame('ecole-ste-marie', School::availableSlugFor('École Ste-Marie'));
    }

    public function test_an_empty_slug_is_never_available(): void
    {
        $this->assertTrue(School::slugIsUnavailable(''));
        $this->assertFalse(School::slugIsUnavailable('school'));
    }

    public function test_existing_normal_and_reserved_slug_behaviour_is_unchanged(): void
    {
        $this->assertSame('springfield-academy', School::availableSlugFor('Springfield Academy'));
        $this->assertSame('login-academy', School::availableSlugFor('Login Academy'));
        $this->assertSame('reset-password-1', School::availableSlugFor('Reset Password'));
        foreach (School::RESERVED_SLUGS as $reserved) {
            $this->assertSame($reserved.'-1', School::availableSlugFor($reserved));
        }

        $this->makeSchool('Springfield Academy', 'springfield-academy');
        $this->assertSame('springfield-academy-1', School::availableSlugFor('Springfield Academy'));
        $this->makeSchool('Something Else', 'reset-password-1');
        $this->assertSame('reset-password-2', School::availableSlugFor('Reset Password'));
    }

    // --------------------------------------------------------- registration

    public function test_registering_a_symbol_only_name_succeeds_and_redirects_to_its_dashboard(): void
    {
        $this->register('###', 'hash@example.test')->assertRedirect('/admin/school/dashboard');

        $school = $this->registered('###');
        $this->assertSame('school', $school->slug);
        $this->assertSame((int) $school->id, (int) session('school_admin_id'));
        $this->get('/admin/school/dashboard')->assertOk()->assertSee('###');
    }

    public function test_the_welcome_email_still_sends_with_working_links(): void
    {
        $this->register('学校', 'cjk@example.test')->assertRedirect('/admin/school/dashboard');

        Mail::assertSent(SchoolLinksMail::class, function (SchoolLinksMail $mail) {
            return $mail->hasTo('cjk@example.test')
                && $mail->links['dashboard'] === route('school.dashboard', ['school' => 'school'])
                && $mail->links['payment'] === route('public.payment', ['school' => 'school']);
        });
    }

    public function test_the_public_payment_url_works(): void
    {
        $this->register('🎓🎓', 'emoji@example.test')->assertRedirect();
        $school = $this->registered('🎓🎓');

        $this->assertSame(url('/pay/school'), $school->paymentUrl());
        $this->flushSession();
        $this->get('/pay/school')->assertOk()->assertSee('🎓🎓');
    }

    public function test_a_symbol_only_school_can_log_in(): void
    {
        $this->register('###', 'hash@example.test')->assertRedirect();
        $this->post('/admin/logout');
        $this->flushSession();

        $this->post('/admin/login', ['name' => '###', 'password' => 'password123'])
            ->assertRedirect('/admin/school/dashboard');
        $this->get('/admin')->assertRedirect('/admin/school/dashboard');
        $this->get('/admin/school/dashboard')->assertOk();
    }

    public function test_later_symbol_only_registrations_get_school_1_and_school_2(): void
    {
        $this->register('###', 'one@example.test')->assertRedirect('/admin/school/dashboard');
        $this->post('/admin/logout');
        $this->register('!!!', 'two@example.test')->assertRedirect('/admin/school-1/dashboard');
        $this->post('/admin/logout');
        $this->register('🎓', 'three@example.test')->assertRedirect('/admin/school-2/dashboard');

        $this->assertSame(['###' => 'school', '!!!' => 'school-1', '🎓' => 'school-2'],
            School::whereIn('name', ['###', '!!!', '🎓'])->orderBy('id')->pluck('slug', 'name')->all());
    }

    public function test_a_school_literally_named_school_stays_isolated_from_a_fallback_slug_school(): void
    {
        $this->register('School', 'named@example.test')->assertRedirect('/admin/school/dashboard');
        $this->post('/admin/logout');
        $this->register('###', 'symbols@example.test')->assertRedirect('/admin/school-1/dashboard');

        $named = $this->registered('School');
        $symbols = $this->registered('###');

        $this->flushSession();
        $this->actingAsSchoolAdmin($named)->get('/admin/school-1/dashboard')->assertNotFound();
        $this->flushSession();
        $this->actingAsSchoolAdmin($symbols)->get('/admin/school/dashboard')->assertNotFound();
        $this->actingAsSchoolAdmin($symbols)->get('/admin/school-1/dashboard')->assertOk();

        $this->flushSession();
        $this->get('/pay/school')->assertOk()->assertSee('School')->assertDontSee('###');
        $this->get('/pay/school-1')->assertOk()->assertSee('###');
    }

    // ------------------------------------------------------ data migration

    public function test_the_migration_repairs_an_empty_slug(): void
    {
        $broken = $this->makeSchool('###', '', ['email' => 'broken@example.test']);

        $this->runRepair();

        $this->assertSame('school', $broken->fresh()->slug);
        $this->flushSession();
        $this->post('/admin/login', ['name' => '###', 'password' => 'password123'])
            ->assertRedirect('/admin/school/dashboard');
    }

    public function test_the_migration_uses_the_same_rules_as_registration(): void
    {
        // `school` and `school-1` are taken, so the repaired row walks on to -2,
        // exactly as School::availableSlugFor would.
        $this->makeSchool('School', 'school');
        $this->makeSchool('Other', 'school-1');
        $broken = $this->makeSchool('学校', '', ['email' => 'cjk@example.test']);
        $expected = School::availableSlugFor('学校');

        $this->runRepair();

        $this->assertSame('school-2', $expected);
        $this->assertSame($expected, $broken->fresh()->slug);

        // A name that DOES slugify keeps its natural slug.
        $renamed = $this->makeSchool('Named Later', '', ['email' => 'later@example.test']);
        $this->runRepair();
        $this->assertSame('named-later', $renamed->fresh()->slug);
    }

    public function test_the_migration_leaves_every_non_empty_slug_unchanged(): void
    {
        // The -1 / -2 slugs later symbol-only schools used to receive, plus
        // ordinary ones, must survive exactly — their links may be shared.
        $this->makeSchool('!!!', '-1', ['email' => 'dash1@example.test']);
        $this->makeSchool('...', '-2', ['email' => 'dash2@example.test']);
        $this->makeSchool('Springfield Academy', 'springfield-academy');
        $this->makeSchool('Reset Password', 'reset-password-1');
        $broken = $this->makeSchool('###', '', ['email' => 'broken@example.test']);

        $before = DB::table('schools')->where('id', '!=', $broken->id)->orderBy('id')->get()->toArray();
        $brokenBefore = (array) DB::table('schools')->where('id', $broken->id)->first();

        $this->runRepair();

        $this->assertEquals($before, DB::table('schools')->where('id', '!=', $broken->id)->orderBy('id')->get()->toArray());

        // On the repaired row only the slug moved.
        $brokenAfter = (array) DB::table('schools')->where('id', $broken->id)->first();
        $this->assertSame('school', $brokenAfter['slug']);
        unset($brokenBefore['slug'], $brokenAfter['slug']);
        $this->assertEquals($brokenBefore, $brokenAfter);
    }

    public function test_the_migration_is_a_no_op_without_empty_slugs(): void
    {
        $this->makeSchool('Alpha School', 'alpha');
        $before = DB::table('schools')->orderBy('id')->get()->toArray();

        $this->runRepair();
        $this->runRepair();

        $this->assertEquals($before, DB::table('schools')->orderBy('id')->get()->toArray());
    }
}
