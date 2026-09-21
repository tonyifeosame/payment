<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * H4 — a school's name (login identifier) and email (password-reset identifier)
 * are unique regardless of case: enforced by registration and settings
 * validation, by unique indexes on LOWER(name) / LOWER(email), and — for any
 * legacy duplicates that predate the indexes — by lookups that fail closed
 * instead of picking the first row.
 */
class SchoolIdentityUniquenessTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const NAME_INDEX = 'schools_name_lower_unique';

    private const EMAIL_INDEX = 'schools_email_lower_unique';

    /** A migration seeds a "Default School" row (no email); every count below is relative to it. */
    private int $baseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseline = School::count();
        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_not-a-real-key']);
        Http::fake(['*/bank/resolve*' => Http::response(['status' => true, 'data' => ['account_name' => 'RESOLVED NAME', 'account_number' => '0123456789']], 200)]);
    }

    private function register(array $overrides = [])
    {
        return $this->post('/registration', array_merge([
            'name' => 'Sunrise Academy', 'email' => 'school@example.com', 'account_number' => '0123456789',
            'bank' => 'GTB', 'bank_code' => '058', 'admin_password' => 'password123', 'admin_password_confirmation' => 'password123',
        ], $overrides));
    }

    /** Simulate a database from before the indexes existed, so duplicates can be inserted. */
    private function dropUniqueIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::NAME_INDEX);
        DB::statement('DROP INDEX IF EXISTS '.self::EMAIL_INDEX);
    }

    private function migration()
    {
        return require base_path('database/migrations/2026_09_23_000000_add_case_insensitive_unique_indexes_to_schools_table.php');
    }

    // =====================================================================
    // 1–3, 9. registration
    // =====================================================================

    public function test_registration_still_works_and_the_same_email_in_another_case_is_rejected(): void
    {
        $this->register()->assertRedirect('/admin/sunrise-academy/dashboard');
        $this->assertDatabaseHas('schools', ['name' => 'Sunrise Academy', 'email' => 'school@example.com']);
        $this->flushSession();

        $this->register(['name' => 'Another School', 'email' => 'SCHOOL@EXAMPLE.COM'])
            ->assertSessionHasErrors(['email' => 'A school is already registered with this email address.']);
        $this->register(['name' => 'Another School', 'email' => '  School@Example.com  '])
            ->assertSessionHasErrors('email');
        $this->assertSame($this->baseline + 1, School::count());
    }

    public function test_registration_with_the_same_name_in_another_case_is_rejected(): void
    {
        $this->register()->assertRedirect();
        $this->flushSession();

        $this->register(['name' => 'SUNRISE academy', 'email' => 'other@example.com'])
            ->assertSessionHasErrors(['name' => 'A school with this name is already registered. Choose a distinct name — it is your login name.']);
        $this->register(['name' => ' sunrise Academy ', 'email' => 'other@example.com'])->assertSessionHasErrors('name');
        $this->assertSame($this->baseline + 1, School::count());

        // A genuinely different name and email registers, and gets its own slug.
        $this->register(['name' => 'Sunrise Academy Lagos', 'email' => 'lagos@example.com'])->assertRedirect('/admin/sunrise-academy-lagos/dashboard');
        $this->assertSame($this->baseline + 2, School::count());
    }

    public function test_settings_cannot_change_a_schools_email_to_another_schools(): void
    {
        $alpha = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
        $beta = $this->makeSchool('Beta School', 'beta', ['email' => 'beta@example.test']);

        $this->actingAsSchoolAdmin($alpha)->put('/admin/alpha/settings', ['name' => 'Alpha School', 'email' => 'BETA@example.test'])
            ->assertSessionHasErrors(['email' => 'Another school is already registered with this email address.']);
        $this->actingAsSchoolAdmin($alpha)->put('/admin/alpha/settings', ['name' => 'beta school', 'email' => 'alpha@example.test'])
            ->assertSessionHasErrors('name');
        // Keeping (or re-casing) its own values is fine.
        $this->actingAsSchoolAdmin($alpha)->put('/admin/alpha/settings', ['name' => 'Alpha School', 'email' => 'Alpha@Example.test'])->assertSessionHasNoErrors();
        $this->assertSame('Alpha@Example.test', $alpha->fresh()->email);
        $this->assertSame('beta@example.test', $beta->fresh()->email, 'beta is untouched');
    }

    // =====================================================================
    // 8. the database enforces it too (races past validation)
    // =====================================================================

    public function test_the_database_refuses_a_case_variant_duplicate_even_without_validation(): void
    {
        $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);

        foreach ([
            ['name' => 'ALPHA SCHOOL', 'slug' => 'alpha-2', 'email' => 'x@example.test'],
            ['name' => 'Other', 'slug' => 'other', 'email' => 'ALPHA@EXAMPLE.TEST'],
        ] as $dup) {
            try {
                School::create(array_merge(['admin_password' => Hash::make('x')], $dup));
                $this->fail('the unique index did not fire for '.json_encode($dup));
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($this->baseline + 1, School::count());

        // Two NULL emails are allowed (email is nullable; NULLs are not "equal").
        School::create(['name' => 'No Mail One', 'slug' => 'nm1', 'email' => null]);
        School::create(['name' => 'No Mail Two', 'slug' => 'nm2', 'email' => null]);
        $this->assertSame($this->baseline + 3, School::count());
    }

    public function test_a_registration_that_loses_the_race_is_reported_not_crashed(): void
    {
        // Validation passes (no such school yet); a competing registration commits
        // the same name between validation and our insert. The constraint fires and
        // the registrant sees a validation message, not a 500.
        $raced = false;
        School::creating(function (School $school) use (&$raced) {
            if (! $raced && $school->slug === 'sunrise-academy') {
                $raced = true;
                DB::table('schools')->insert(['name' => 'SUNRISE ACADEMY', 'slug' => 'sunrise-academy-race', 'email' => 'race@example.com', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->register()
            ->assertRedirect()
            ->assertSessionHasErrors(['name' => 'A school with this name or email address was registered a moment ago. Choose a distinct name and email.']);

        $this->assertSame($this->baseline + 1, School::count());
        $this->assertSame('sunrise-academy-race', School::where('slug', 'like', 'sunrise-academy%')->sole()->slug, 'the database kept exactly one');
        $this->assertNull(session('school_admin_id'), 'the loser is not signed in to the winner');
    }

    // =====================================================================
    // 5–6. legacy duplicates: fail closed, never "the first one"
    // =====================================================================

    public function test_login_refuses_an_ambiguous_school_name_instead_of_choosing_one(): void
    {
        $this->dropUniqueIndexes();
        $first = School::create(['name' => 'Twin School', 'slug' => 'twin-1', 'email' => 'one@example.test', 'admin_password' => Hash::make('secret-one')]);
        $second = School::create(['name' => 'twin school', 'slug' => 'twin-2', 'email' => 'two@example.test', 'admin_password' => Hash::make('secret-two')]);

        foreach (['secret-one', 'secret-two'] as $password) {
            $this->post('/admin/login', ['name' => 'Twin School', 'password' => $password])
                ->assertRedirect()->assertSessionHas('error', 'Invalid school name or password.');
            $this->assertNull(session('school_admin_id'), 'an ambiguous name must never authenticate');
        }

        $this->assertNull(School::findUniqueByName('Twin School'));
        $this->assertNull(School::findUniqueByName('TWIN SCHOOL'));
        // Unambiguous names still resolve, case-insensitively.
        $this->makeSchool('Only School', 'only', ['email' => 'only@example.test']);
        $this->assertSame('Only School', School::findUniqueByName('only school')?->name);
        $this->post('/admin/login', ['name' => 'ONLY SCHOOL', 'password' => 'password123'])->assertRedirect('/admin/only/dashboard');
        $this->assertSame(School::where('slug', 'only')->value('id'), session('school_admin_id'));
        $this->assertNotContains(session('school_admin_id'), [$first->id, $second->id]);
    }

    public function test_password_reset_refuses_an_ambiguous_email_instead_of_choosing_one(): void
    {
        $this->dropUniqueIndexes();
        $first = School::create(['name' => 'One', 'slug' => 'one', 'email' => 'shared@example.test', 'admin_password' => Hash::make('secret-one')]);
        $second = School::create(['name' => 'Two', 'slug' => 'two', 'email' => 'SHARED@example.test', 'admin_password' => Hash::make('secret-two')]);

        // Request: neutral message, no mail, no token for either school.
        $this->post('/admin/forgot-password', ['email' => 'shared@example.test'])
            ->assertRedirect()->assertSessionHas('status');
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        // Reset with a token minted for one of them: still refused, because the
        // email cannot identify a single school.
        $token = Password::createToken($first);
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'shared@example.test', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])
            ->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('secret-one', $first->fresh()->admin_password));
        $this->assertTrue(Hash::check('secret-two', $second->fresh()->admin_password));

        // An unambiguous email still resets, case-insensitively.
        $only = $this->makeSchool('Only', 'only', ['email' => 'only@example.test']);
        $this->post('/admin/forgot-password', ['email' => 'ONLY@example.test'])->assertRedirect();
        Mail::assertSent(\App\Mail\SchoolPasswordResetMail::class, 1);
        $token = Password::createToken($only);
        $this->post('/admin/reset-password', ['token' => $token, 'email' => 'Only@Example.test', 'password' => 'brand-new-secret-9', 'password_confirmation' => 'brand-new-secret-9'])
            ->assertRedirect('/admin/login');
        $this->assertTrue(Hash::check('brand-new-secret-9', $only->fresh()->admin_password));
    }

    // =====================================================================
    // migration behaviour with historical duplicates
    // =====================================================================

    public function test_the_migration_refuses_to_run_over_historical_duplicates_and_changes_nothing(): void
    {
        $this->dropUniqueIndexes();
        School::create(['name' => 'Legacy School', 'slug' => 'legacy-1', 'email' => 'legacy@example.test']);
        School::create(['name' => 'LEGACY SCHOOL', 'slug' => 'legacy-2', 'email' => 'Legacy@example.test']);
        $unique = School::create(['name' => 'Fine School', 'slug' => 'fine', 'email' => 'fine@example.test']);
        $before = School::orderBy('id')->get()->map->only('id', 'name', 'slug', 'email')->all();

        try {
            $this->migration()->up();
            $this->fail('the migration must refuse duplicates');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot add case-insensitive unique indexes', $e->getMessage());
            $this->assertStringContainsString('No data was changed', $e->getMessage());
            $this->assertStringContainsString('name "legacy school" is shared by school ids', $e->getMessage());
            $this->assertStringContainsString('email "legacy@example.test" is shared by school ids', $e->getMessage());
        }

        $this->assertSame($before, School::orderBy('id')->get()->map->only('id', 'name', 'slug', 'email')->all(), 'no row was deleted, renamed or altered');
        $this->assertSame($this->baseline + 3, School::count());
        // No index was created for either column (the run is all-or-nothing).
        School::create(['name' => 'fine school', 'slug' => 'fine-2', 'email' => 'FINE@example.test']);
        $this->assertSame($this->baseline + 4, School::count());

        // Once an operator resolves the duplicates by hand, the migration runs.
        School::where('slug', 'fine-2')->delete();
        School::where('slug', 'legacy-2')->update(['name' => 'Legacy School Ikeja', 'email' => 'legacy-ikeja@example.test']);
        $this->migration()->up();
        try {
            School::create(['name' => 'legacy school', 'slug' => 'legacy-3', 'email' => 'z@example.test']);
            $this->fail('index not in place');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        // …and is reversible: with the indexes dropped the duplicate is accepted
        // again, and re-running up() over it refuses again.
        $this->migration()->down();
        School::create(['name' => 'legacy school', 'slug' => 'legacy-3', 'email' => 'z@example.test']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No data was changed');
        $this->migration()->up();
    }

    // =====================================================================
    // 7. tenant isolation is unaffected
    // =====================================================================

    public function test_tenant_isolation_holds_across_similarly_named_schools(): void
    {
        $alpha = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
        $alphaLagos = $this->makeSchool('Alpha School Lagos', 'alpha-lagos', ['email' => 'alpha-lagos@example.test']);
        $student = $this->makeStudent($alphaLagos, 'L/1', 'Lagos Student');

        $this->post('/admin/login', ['name' => 'alpha school', 'password' => 'password123'])->assertRedirect('/admin/alpha/dashboard');
        $this->assertSame($alpha->id, session('school_admin_id'));
        $this->get('/admin/alpha-lagos/dashboard')->assertNotFound();
        $this->get("/admin/alpha/students/{$student->id}")->assertNotFound();
        $this->get('/admin/alpha/students')->assertOk()->assertDontSee('Lagos Student');
    }
}
