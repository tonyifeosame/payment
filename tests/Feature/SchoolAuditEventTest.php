<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Subcategory;
use App\Support\RecordsSchoolAudit;
use App\Support\SchoolSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M7 — a durable record of the school-admin actions that change identity, move
 * money or destroy a row.
 *
 * What these tests can and cannot prove. A school has one shared credential, so
 * nothing here identifies a person: `actor` is a role and `actor_session` is a
 * hash that separates one signed-in session from another. The assertions below
 * are written to that limit deliberately — they prove the trail answers "when,
 * which school, what changed, one sitting or several", never "who".
 */
class SchoolAuditEventTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
        $this->beta = $this->makeSchool('Beta School', 'beta', ['email' => 'beta@example.test']);
    }

    private function events(?string $action = null)
    {
        return SchoolAuditEvent::query()
            ->when($action, fn ($q) => $q->where('action', $action))
            ->orderBy('id')
            ->get();
    }

    private function onlyEvent(string $action): SchoolAuditEvent
    {
        $events = $this->events($action);
        $this->assertCount(1, $events, "expected exactly one {$action} event, got ".$events->count());

        return $events->first();
    }

    /**
     * Behave like a browser: send the session cookie back, so the session id is
     * carried across requests. Without this the test client starts a NEW session
     * per request — which would make every audit row look like a separate sitting
     * and hide whether the hash is actually stable.
     */
    private function browser(): static
    {
        return $this->withCookie(session()->getName(), session()->getId());
    }

    private function fee(array $overrides = []): Subcategory
    {
        return $this->makeFee($this->alpha, 'Tuition', 'First Term', 50000);
    }

    // ============================================== Tier 1: identity & money

    public function test_a_profile_change_records_only_the_identity_fields_that_moved(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', [
                'name' => 'Alpha Academy',           // changed: the login identifier
                'email' => 'alpha@example.test',     // unchanged
                'phone' => '08012345678',            // changed, but not audited
                'address' => 'New address',          // changed, but not audited
            ])->assertRedirect();

        $event = $this->onlyEvent(SchoolAuditEvent::ACTION_PROFILE_CHANGED);

        $this->assertSame($this->alpha->id, $event->school_id);
        $this->assertSame('school', $event->subject_type);
        $this->assertSame($this->alpha->id, $event->subject_id);
        $this->assertSame(SchoolAuditEvent::ACTOR_SCHOOL_ADMIN, $event->actor);

        // Only the changed identity field, and nothing else.
        $this->assertSame(['name'], array_keys($event->changes));
        $this->assertSame('Alpha School', $event->changes['name']['from']);
        $this->assertSame('Alpha Academy', $event->changes['name']['to']);
        $this->assertArrayNotHasKey('phone', $event->changes);
        $this->assertArrayNotHasKey('address', $event->changes);
    }

    public function test_a_profile_save_that_changes_no_identity_field_records_nothing(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', [
                'name' => 'Alpha School',
                'email' => 'alpha@example.test',
                'phone' => '08012345678',
            ])->assertRedirect();

        $this->assertCount(0, $this->events(SchoolAuditEvent::ACTION_PROFILE_CHANGED));
    }

    public function test_a_password_change_is_recorded_with_no_password_material(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings/password', [
                'current_password' => 'password123',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])->assertRedirect();

        $event = $this->onlyEvent(SchoolAuditEvent::ACTION_PASSWORD_CHANGED);

        $this->assertSame($this->alpha->id, $event->school_id);
        $this->assertNull($event->changes, 'a password change must record no values at all');

        // Nothing in the whole row resembles the old or new password, or the hash.
        $row = json_encode($event->getAttributes());
        $this->assertStringNotContainsString('password123', $row);
        $this->assertStringNotContainsString('a-brand-new-secret', $row);
        $this->assertStringNotContainsString('$2y$', $row);
        $this->assertStringNotContainsString((string) $this->alpha->fresh()->admin_password, $row);
    }

    public function test_a_bank_change_records_last_four_only_and_no_secrets(): void
    {
        $this->alpha->forceFill([
            'bank' => 'GTB', 'bank_code' => '058',
            'account_number' => '0123456789', 'account_name' => 'OLD NAME',
            'paystack_recipient_code' => 'RCP_secret_handle',
        ])->save();

        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'ALPHA SCHOOL LTD', 'account_number' => '9876543210'],
        ], 200)]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings/bank', [
                'bank' => 'Zenith Bank',
                'bank_code' => '057',
                'account_number' => '9876543210',
                'current_password' => 'password123',
            ])->assertRedirect();

        $event = $this->onlyEvent(SchoolAuditEvent::ACTION_BANK_CHANGED);
        $row = json_encode($event->getAttributes());

        $this->assertSame('6789', $event->changes['account_last4']['from']);
        $this->assertSame('3210', $event->changes['account_last4']['to']);
        $this->assertSame('GTB', $event->changes['bank']['from']);
        $this->assertSame('Zenith Bank', $event->changes['bank']['to']);

        // Never the full numbers, the bank code, or the recipient handle.
        $this->assertStringNotContainsString('0123456789', $row);
        $this->assertStringNotContainsString('9876543210', $row);
        $this->assertStringNotContainsString('RCP_secret_handle', $row);
        $this->assertStringNotContainsString('sk_test_fake', $row);
        $this->assertArrayNotHasKey('account_number', $event->changes);
        $this->assertArrayNotHasKey('bank_code', $event->changes);
    }

    public function test_a_fee_create_update_and_delete_are_each_recorded_once(): void
    {
        $category = Category::create(['school_id' => $this->alpha->id, 'name' => 'Tuition']);
        $admin = $this->actingAsSchoolAdmin($this->alpha);

        $admin->post('/admin/alpha/subcategories', [
            'category_id' => $category->id, 'name' => 'First Term', 'price' => 50000,
        ])->assertRedirect();

        $created = $this->onlyEvent(SchoolAuditEvent::ACTION_FEE_CREATED);
        $fee = Subcategory::where('school_id', $this->alpha->id)->sole();
        $this->assertSame('subcategory', $created->subject_type);
        $this->assertSame($fee->id, $created->subject_id);
        $this->assertEqualsWithDelta(50000, (float) $created->changes['price']['to'], 0.001);
        $this->assertNull($created->changes['price']['from']);

        $admin->put('/admin/alpha/subcategories/'.$fee->id, [
            'category_id' => $category->id, 'name' => 'First Term', 'price' => 65000,
        ])->assertRedirect();

        $updated = $this->onlyEvent(SchoolAuditEvent::ACTION_FEE_UPDATED);
        $this->assertSame(['price'], array_keys($updated->changes), 'only the price moved');
        $this->assertEqualsWithDelta(50000, (float) $updated->changes['price']['from'], 0.001);
        $this->assertEqualsWithDelta(65000, (float) $updated->changes['price']['to'], 0.001);

        $admin->delete('/admin/alpha/subcategories/'.$fee->id)->assertRedirect();

        $deleted = $this->onlyEvent(SchoolAuditEvent::ACTION_FEE_DELETED);
        $this->assertSame($fee->id, $deleted->subject_id);
        $this->assertSame('First Term', $deleted->changes['name']['from']);
        $this->assertEqualsWithDelta(65000, (float) $deleted->changes['price']['from'], 0.001);
    }

    public function test_a_fee_update_that_changes_nothing_records_nothing(): void
    {
        $fee = $this->fee();

        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/subcategories/'.$fee->id, [
                'category_id' => $fee->category_id, 'name' => $fee->name, 'price' => 50000,
            ])->assertRedirect();

        $this->assertCount(0, $this->events(SchoolAuditEvent::ACTION_FEE_UPDATED));
    }

    public function test_a_current_term_change_is_recorded_once_and_is_idempotent(): void
    {
        $session = $this->makeSessionWithTerms($this->alpha);
        $terms = $session->terms()->orderBy('number')->get();
        $second = $terms[1];

        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/terms/'.$second->id.'/current')->assertRedirect();

        $event = $this->onlyEvent(SchoolAuditEvent::ACTION_TERM_CHANGED);
        $this->assertSame('academic_term', $event->subject_type);
        $this->assertSame($second->id, $event->subject_id);
        $this->assertSame($second->id, $event->changes['current_academic_term_id']['to']);

        // Setting the same term again changes nothing, so it records nothing.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/terms/'.$second->id.'/current')->assertRedirect();

        $this->assertCount(1, $this->events(SchoolAuditEvent::ACTION_TERM_CHANGED));
    }

    // ===================================================== Tier 2: destructive

    public function test_a_deleted_category_leaves_a_complete_event(): void
    {
        $category = Category::create(['school_id' => $this->alpha->id, 'name' => 'Excursions']);

        $this->actingAsSchoolAdmin($this->alpha)
            ->delete('/admin/alpha/categories/'.$category->id)->assertRedirect();

        $event = $this->onlyEvent(SchoolAuditEvent::ACTION_CATEGORY_DELETED);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        $this->assertSame($category->id, $event->subject_id);
        $this->assertSame('Excursions', $event->changes['name']['from'], 'the event is the only surviving record');
    }

    public function test_a_deleted_class_level_leaves_a_complete_event(): void
    {
        $level = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS 1', 'position' => 1]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->delete('/admin/alpha/students/classes/'.$level->id)->assertRedirect();

        $event = $this->onlyEvent(SchoolAuditEvent::ACTION_CLASS_LEVEL_DELETED);

        $this->assertDatabaseMissing('class_levels', ['id' => $level->id]);
        $this->assertSame($level->id, $event->subject_id);
        $this->assertSame('JSS 1', $event->changes['name']['from']);
    }

    // ================================================= actor_session semantics

    public function test_the_raw_session_id_is_never_stored(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', ['name' => 'Alpha Academy', 'email' => 'alpha@example.test'])
            ->assertRedirect();

        $event = $this->onlyEvent(SchoolAuditEvent::ACTION_PROFILE_CHANGED);

        $this->assertNotNull($event->actor_session);
        $this->assertSame(64, strlen($event->actor_session), 'actor_session is not a SHA-256 hex digest');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $event->actor_session);

        // Whatever session id the request carried, it is not in the row.
        $raw = session()->getId();
        $this->assertNotSame($raw, $event->actor_session);
        $this->assertStringNotContainsString($raw, json_encode($event->getAttributes()));
        $this->assertSame(hash('sha256', $raw), $event->actor_session);
    }

    public function test_one_session_produces_a_stable_hash_across_actions(): void
    {
        $fee = $this->fee();
        $this->actingAsSchoolAdmin($this->alpha);

        $this->browser()
            ->put('/admin/alpha/settings', ['name' => 'Alpha Academy', 'email' => 'alpha@example.test'])
            ->assertRedirect();
        $this->browser()
            ->delete('/admin/alpha/subcategories/'.$fee->id)
            ->assertRedirect();

        $hashes = $this->events()->pluck('actor_session')->unique();

        $this->assertCount(2, $this->events());
        $this->assertCount(1, $hashes, 'one sitting should group under one hash');
        $this->assertSame(hash('sha256', session()->getId()), $hashes->first());
    }

    public function test_different_sessions_produce_different_hashes(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', ['name' => 'Alpha Academy', 'email' => 'alpha@example.test'])
            ->assertRedirect();
        $first = $this->events()->first()->actor_session;

        // A second, separate sitting for the same school.
        $this->flushSession();
        $this->actingAsSchoolAdmin($this->alpha->fresh())
            ->put('/admin/alpha/settings', ['name' => 'Alpha College', 'email' => 'alpha@example.test'])
            ->assertRedirect();

        $second = $this->events()->last()->actor_session;

        $this->assertNotSame($first, $second, 'two sessions must be distinguishable');
    }

    // ============================================================= atomicity

    public function test_a_rolled_back_mutation_leaves_no_event(): void
    {
        $fee = $this->fee();
        $audit = app(RecordsSchoolAudit::class);

        try {
            DB::transaction(function () use ($fee, $audit) {
                $fee->update(['price' => 99999]);
                $audit->record($this->alpha, SchoolAuditEvent::ACTION_FEE_UPDATED, 'subcategory', $fee->id, [
                    'price' => ['from' => 50000, 'to' => 99999],
                ]);

                throw new \RuntimeException('something failed after both writes');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(0, $this->events(), 'the audit event outlived its rolled-back mutation');
        $this->assertEqualsWithDelta(50000, (float) $fee->fresh()->price, 0.001, 'the mutation was not rolled back');
    }

    public function test_the_recorder_opens_no_transaction_of_its_own(): void
    {
        $audit = app(RecordsSchoolAudit::class);

        // RefreshDatabase already holds a transaction around the whole test, so
        // levels are asserted RELATIVE to wherever we start.
        $base = DB::transactionLevel();

        DB::transaction(function () use ($audit, $base) {
            $this->assertSame($base + 1, DB::transactionLevel());
            $audit->record($this->alpha, SchoolAuditEvent::ACTION_PASSWORD_CHANGED, 'school', $this->alpha->id);
            $this->assertSame($base + 1, DB::transactionLevel(), 'the recorder nested a transaction of its own');
        });

        $this->assertSame($base, DB::transactionLevel());
    }

    public function test_a_failed_mutation_records_nothing(): void
    {
        $fee = $this->fee();

        // Validation fails (no name), so the update never happens.
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/subcategories/'.$fee->id, ['category_id' => $fee->category_id, 'price' => 9999])
            ->assertSessionHasErrors();

        $this->assertCount(0, $this->events());
        $this->assertEqualsWithDelta(50000, (float) $fee->fresh()->price, 0.001);
    }

    // ============================================================ immutability

    public function test_the_model_is_immutable(): void
    {
        $audit = app(RecordsSchoolAudit::class);
        $event = $audit->record($this->alpha, SchoolAuditEvent::ACTION_PASSWORD_CHANGED, 'school', $this->alpha->id);

        $this->assertNull(SchoolAuditEvent::UPDATED_AT);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('school_audit_events', 'updated_at'));
        $this->assertNotNull($event->created_at);
    }

    // ======================================================= tenant isolation

    public function test_events_are_scoped_to_the_school_that_acted(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', ['name' => 'Alpha Academy', 'email' => 'alpha@example.test'])
            ->assertRedirect();

        $this->flushSession();
        $this->actingAsSchoolAdmin($this->beta)
            ->put('/admin/beta/settings', ['name' => 'Beta Academy', 'email' => 'beta@example.test'])
            ->assertRedirect();

        $this->assertCount(1, SchoolAuditEvent::where('school_id', $this->alpha->id)->get());
        $this->assertCount(1, SchoolAuditEvent::where('school_id', $this->beta->id)->get());

        // Beta's admin cannot reach alpha's settings at all, so cannot write to
        // alpha's trail either.
        // alpha's REAL slug — a made-up one would 404 on route-model binding and
        // prove nothing about tenant scoping.
        $this->flushSession();
        $this->actingAsSchoolAdmin($this->beta)
            ->put('/admin/alpha/settings', ['name' => 'Hijacked', 'email' => 'x@example.test'])
            ->assertNotFound();

        $this->assertCount(1, SchoolAuditEvent::where('school_id', $this->alpha->id)->get());
    }

    public function test_an_unauthenticated_request_records_nothing(): void
    {
        $fee = $this->fee();

        $this->put('/admin/alpha/settings', ['name' => 'Alpha Academy', 'email' => 'alpha@example.test'])
            ->assertRedirect('/admin/login');
        $this->delete('/admin/alpha/subcategories/'.$fee->id)->assertRedirect('/admin/login');
        $this->put('/admin/alpha/settings/password', [
            'current_password' => 'password123', 'password' => 'x-new-secret-9', 'password_confirmation' => 'x-new-secret-9',
        ])->assertRedirect('/admin/login');

        $this->assertCount(0, $this->events());
        $this->assertSame('Alpha School', $this->alpha->fresh()->name);
        $this->assertTrue(Hash::check('password123', $this->alpha->fresh()->admin_password));
    }

    public function test_a_revoked_session_records_nothing(): void
    {
        // H6: a session that predates a password change no longer authenticates.
        $session = SchoolSession::payloadFor($this->alpha);
        $this->alpha->forceFill(['admin_password' => Hash::make('rotated-secret-9')])->save();

        $this->withSession($session)
            ->put('/admin/alpha/settings', ['name' => 'Alpha Academy', 'email' => 'alpha@example.test'])
            ->assertRedirect('/admin/login');

        $this->assertCount(0, $this->events());
    }
}
