<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\School;
use App\Models\SchoolLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * H3 — school logos live in the database (school_logos), not on the container
 * filesystem, so they survive a Render redeploy and are identical on the web,
 * worker and cron services. The public URL (/s/{school}/logo), the validation
 * rules and every consumer are unchanged.
 */
class SchoolLogoTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        // Faked so the assertions below can prove nothing is ever written to it.
        Storage::fake('local');

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alpha School',
            'email' => 'alpha@example.test',
        ], $overrides);
    }

    private function upload(School $school, UploadedFile $file)
    {
        return $this->actingAsSchoolAdmin($school)
            ->put('/admin/'.$school->slug.'/settings', [
                'name' => $school->name,
                'email' => $school->email,
                'logo' => $file,
            ])
            ->assertRedirect('/admin/'.$school->slug.'/settings')
            ->assertSessionHasNoErrors();
    }

    // -----------------------------------------------------------------------
    // Storage
    // -----------------------------------------------------------------------

    public function test_upload_persists_in_the_database_and_writes_no_file(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 200, 200);
        $bytes = $file->get();

        $this->upload($this->alpha, $file);

        $logo = SchoolLogo::find($this->alpha->id);
        $this->assertNotNull($logo);
        $this->assertSame('image/png', $logo->mime);
        $this->assertSame(strlen($bytes), $logo->size);
        $this->assertSame($bytes, $logo->bytes(), 'the stored bytes must round-trip exactly');
        $this->assertSame(base64_encode($bytes), $logo->getRawOriginal('data'), 'stored as base64 text, safe for every driver');

        $this->assertSame([], Storage::disk('local')->allFiles(), 'no logo may touch the container filesystem');
        $this->assertFalse(Schema::hasColumn('schools', 'logo_path'));
    }

    public function test_logo_route_returns_the_exact_image(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 64, 64);
        $bytes = $file->get();
        $this->upload($this->alpha, $file);

        $response = $this->get('/s/alpha/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=86400, public');

        $this->assertSame($bytes, $response->getContent());
    }

    public function test_replacing_a_png_with_a_webp_replaces_the_row(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));
        $this->assertDatabaseHas('school_logos', ['school_id' => $this->alpha->id, 'mime' => 'image/png']);

        $webp = UploadedFile::fake()->image('logo.webp', 48, 48);
        $webpBytes = $webp->get();
        $this->upload($this->alpha, $webp);

        $this->assertSame(1, SchoolLogo::where('school_id', $this->alpha->id)->count(), 'one row per school, replaced in place');
        $this->assertDatabaseHas('school_logos', ['school_id' => $this->alpha->id, 'mime' => 'image/webp', 'size' => strlen($webpBytes)]);
        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id, 'mime' => 'image/png']);

        $response = $this->get('/s/alpha/logo')->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertSame($webpBytes, $response->getContent());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_removing_the_logo_deletes_the_row_and_only_the_row(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));
        $this->giveLogo($this->beta);

        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/settings', $this->profile(['remove_logo' => 1]))
            ->assertRedirect('/admin/alpha/settings')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);
        $this->assertDatabaseHas('school_logos', ['school_id' => $this->beta->id]);
        $this->assertDatabaseHas('schools', ['id' => $this->alpha->id, 'name' => 'Alpha School']);
        $this->get('/s/alpha/logo')->assertNotFound();
        $this->get('/s/beta/logo')->assertOk();
    }

    public function test_validation_is_unchanged(): void
    {
        foreach ([
            UploadedFile::fake()->create('evil.php', 10, 'text/plain'),
            UploadedFile::fake()->create('evil.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->image('big.png', 10, 10)->size(1025), // > 1024 KB
        ] as $file) {
            $this->actingAsSchoolAdmin($this->alpha)
                ->from('/admin/alpha/settings')
                ->put('/admin/alpha/settings', $this->profile(['logo' => $file]))
                ->assertRedirect('/admin/alpha/settings')
                ->assertSessionHasErrors('logo');
        }

        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);

        foreach (['logo.jpg', 'logo.jpeg', 'logo.png', 'logo.webp'] as $name) {
            $this->upload($this->alpha, UploadedFile::fake()->image($name, 16, 16));
        }
        $this->assertDatabaseHas('school_logos', ['school_id' => $this->alpha->id, 'mime' => 'image/webp']);
    }

    public function test_deleting_a_school_cascades_to_its_logo(): void
    {
        $this->giveLogo($this->alpha);
        $this->giveLogo($this->beta);

        $this->alpha->delete();

        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);
        $this->assertDatabaseHas('school_logos', ['school_id' => $this->beta->id]);
    }

    // -----------------------------------------------------------------------
    // HTTP caching: ETag / Last-Modified / 304, and the cache-busting URL
    // -----------------------------------------------------------------------

    public function test_logo_route_supports_conditional_requests(): void
    {
        $this->travelTo('2026-09-22 10:00:00');
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));

        $first = $this->get('/s/alpha/logo')->assertOk();
        $etag = $first->headers->get('ETag');
        $lastModified = $first->headers->get('Last-Modified');

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag, 'a strong validator derived from the bytes');
        $this->assertSame('Tue, 22 Sep 2026 10:00:00 GMT', $lastModified);

        // The validators are the same on every container, so a client that got
        // them from the web service is answered 304 with no body.
        $this->get('/s/alpha/logo', ['If-None-Match' => $etag])->assertStatus(304)->assertNoContent(304);
        $this->get('/s/alpha/logo', ['If-Modified-Since' => $lastModified])->assertStatus(304);
        $this->get('/s/alpha/logo', ['If-Modified-Since' => 'Tue, 22 Sep 2026 09:59:59 GMT'])->assertOk();
        $this->get('/s/alpha/logo', ['If-None-Match' => '"stale"'])->assertOk();

        // A replaced logo invalidates both.
        $this->travelTo('2026-09-22 11:00:00');
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.webp', 48, 48));

        $second = $this->get('/s/alpha/logo', ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $second->headers->get('ETag'));
        $this->assertSame('Tue, 22 Sep 2026 11:00:00 GMT', $second->headers->get('Last-Modified'));
        $this->get('/s/alpha/logo', ['If-Modified-Since' => $lastModified])->assertOk();

        // Re-uploading identical bytes changes nothing, so the validators hold.
        $this->travelTo('2026-09-22 12:00:00');
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.webp', 48, 48));
        $this->get('/s/alpha/logo', ['If-None-Match' => $second->headers->get('ETag')])->assertStatus(304);
        $this->get('/s/alpha/logo')->assertOk()->assertHeader('Last-Modified', 'Tue, 22 Sep 2026 11:00:00 GMT');
    }

    public function test_logo_url_cache_buster_changes_when_the_logo_does(): void
    {
        $this->travelTo('2026-09-22 10:00:00');
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));
        $before = $this->alpha->fresh()->logoUrl();
        $this->assertStringStartsWith('http://localhost/s/alpha/logo?v=', $before);

        // An unrelated profile save does not rotate it.
        $this->travelTo('2026-09-22 10:30:00');
        $this->actingAsSchoolAdmin($this->alpha)->put('/admin/alpha/settings', $this->profile(['phone' => '0801 234 5678']))->assertSessionHasNoErrors();
        $this->assertNotSame($before, $this->alpha->fresh()->logoUrl(), 'the school row was saved, so v moved with updated_at as before');

        $unchanged = $this->alpha->fresh()->logoUrl();
        $this->travelTo('2026-09-22 11:00:00');
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.webp', 48, 48));
        $this->assertNotSame($unchanged, $this->alpha->fresh()->logoUrl(), 'a new logo must bust the day-long browser cache');

        // Removal: URL gone.
        $this->actingAsSchoolAdmin($this->alpha)->put('/admin/alpha/settings', $this->profile(['remove_logo' => 1]));
        $this->assertNull($this->alpha->fresh()->logoUrl());
    }

    // -----------------------------------------------------------------------
    // Consumers
    // -----------------------------------------------------------------------

    public function test_every_consumer_still_renders_the_logo(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));
        $transaction = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'alpha-ref-1']);

        $this->get('/s/alpha/payment')->assertOk()->assertSee('/s/alpha/logo?v=', false);
        $this->get('/pay/alpha')->assertOk()->assertSee('/s/alpha/logo?v=', false);
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/share')->assertOk()->assertSee('/s/alpha/logo?v=', false);
        $this->get(URL::signedRoute('payment.receipt', ['transaction' => $transaction->id]))->assertOk()->assertSee('/s/alpha/logo?v=', false);

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/settings')->assertOk()
            ->assertSee('/s/alpha/logo?v=', false)
            ->assertSee('Remove the current logo');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard')->assertOk()->assertSee('/s/alpha/logo?v=', false);

        // PDF: rendered from a data URI so Dompdf never makes an HTTP request.
        $this->assertStringStartsWith('data:image/png;base64,', $this->alpha->fresh()->logoDataUri());
        $pdf = $this->get(URL::signedRoute('payment.receipt.download', ['transaction' => $transaction->id]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function test_queued_receipt_email_renders_the_logo_from_the_database(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));
        $transaction = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'alpha-ref-1']);

        // The worker rehydrates the mailable from the queue payload: no request,
        // no relations, and — the point of H3 — no shared filesystem with the web
        // service. The logo still has to be there.
        Storage::disk('local')->deleteDirectory('school-logos');
        $mailable = unserialize(serialize(new PaymentReceiptMail($transaction)));

        $html = $mailable->render();
        $this->assertStringContainsString('http://localhost/s/alpha/logo?v=', $html);
        $this->assertStringContainsString('alt="Alpha School logo"', $html);

        // And no logo, no <img>.
        SchoolLogo::where('school_id', $this->alpha->id)->delete();
        $this->assertStringNotContainsString('/s/alpha/logo', unserialize(serialize(new PaymentReceiptMail($transaction->fresh())))->render());
    }

    // -----------------------------------------------------------------------
    // Tenant isolation
    // -----------------------------------------------------------------------

    public function test_logos_are_isolated_per_school(): void
    {
        $alphaFile = UploadedFile::fake()->image('alpha.png', 32, 32);
        $alphaBytes = $alphaFile->get();
        $this->upload($this->alpha, $alphaFile);

        // Beta has none: its URL 404s and never falls through to alpha's row.
        $this->get('/s/beta/logo')->assertNotFound();
        $this->assertFalse($this->beta->fresh()->hasLogo());
        $this->get('/s/beta/payment')->assertOk()->assertDontSee('/s/alpha/logo', false)->assertDontSee('/s/beta/logo', false);

        // Beta's admin cannot replace or remove alpha's logo.
        $this->actingAsSchoolAdmin($this->beta)
            ->put('/admin/alpha/settings', $this->profile(['logo' => UploadedFile::fake()->image('hijack.png', 32, 32)]))
            ->assertNotFound();
        $this->actingAsSchoolAdmin($this->beta)
            ->put('/admin/alpha/settings', $this->profile(['remove_logo' => 1]))
            ->assertNotFound();
        $this->assertSame($alphaBytes, SchoolLogo::find($this->alpha->id)->bytes());

        // Beta uploading its own logo leaves alpha's untouched, and each URL serves its own.
        $betaFile = UploadedFile::fake()->image('beta.webp', 40, 40);
        $betaBytes = $betaFile->get();
        $this->upload($this->beta, $betaFile);

        $this->assertSame(2, SchoolLogo::count());
        $this->assertSame($alphaBytes, $this->get('/s/alpha/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->getContent());
        $this->assertSame($betaBytes, $this->get('/s/beta/logo')->assertOk()->assertHeader('Content-Type', 'image/webp')->getContent());
        $this->assertNotSame(
            $this->get('/s/alpha/logo')->headers->get('ETag'),
            $this->get('/s/beta/logo')->headers->get('ETag')
        );
    }

    // -----------------------------------------------------------------------
    // Migration: backfill from the old logo_path files, then drop the column
    // -----------------------------------------------------------------------

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_24_000000_create_school_logos_table.php');
    }

    /** Put the schema back to the pre-H3 shape: logo_path column, no table. */
    private function rewindSchema(): void
    {
        Schema::dropIfExists('school_logos');
        Schema::table('schools', fn ($table) => $table->string('logo_path')->nullable());
    }

    public function test_migration_backfills_present_files_skips_missing_ones_and_drops_the_column(): void
    {
        $this->rewindSchema();

        $present = UploadedFile::fake()->image('logo.png', 30, 30);
        $presentBytes = $present->get();
        Storage::disk('local')->putFileAs('school-logos', $present, $this->alpha->id.'.png');
        DB::table('schools')->where('id', $this->alpha->id)->update(['logo_path' => 'school-logos/'.$this->alpha->id.'.png']);
        DB::table('schools')->where('id', $this->beta->id)->update(['logo_path' => 'school-logos/'.$this->beta->id.'.png']); // file gone: the Render case
        $gamma = $this->makeSchool('Gamma School', 'gamma'); // never had one
        $schoolsBefore = School::count();

        $this->migration()->up();

        $this->assertTrue(Schema::hasTable('school_logos'));
        $this->assertFalse(Schema::hasColumn('schools', 'logo_path'));

        $logo = SchoolLogo::find($this->alpha->id);
        $this->assertSame('image/png', $logo->mime);
        $this->assertSame(strlen($presentBytes), $logo->size);
        $this->assertSame($presentBytes, $logo->bytes());
        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->beta->id]);
        $this->assertDatabaseMissing('school_logos', ['school_id' => $gamma->id]);
        // Nothing was deleted: the schools are intact and the file is still on disk.
        $this->assertSame($schoolsBefore, School::count());
        Storage::disk('local')->assertExists('school-logos/'.$this->alpha->id.'.png');

        $this->get('/s/alpha/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/s/beta/logo')->assertNotFound();
    }

    public function test_migration_is_idempotent_and_reversible(): void
    {
        $this->rewindSchema();

        Storage::disk('local')->putFileAs('school-logos', UploadedFile::fake()->image('logo.png', 30, 30), $this->alpha->id.'.png');
        DB::table('schools')->where('id', $this->alpha->id)->update(['logo_path' => 'school-logos/'.$this->alpha->id.'.png']);

        $migration = $this->migration();

        // A second up() after a completed one is a no-op, not an error.
        $migration->up();
        $migration->up();
        $this->assertSame(1, SchoolLogo::count());
        $this->assertFalse(Schema::hasColumn('schools', 'logo_path'));

        // A logo uploaded after the migration is written back to disk on rollback.
        $this->upload($this->beta, UploadedFile::fake()->image('beta.webp', 20, 20));
        $betaBytes = SchoolLogo::find($this->beta->id)->bytes();

        $migration->down();

        $this->assertFalse(Schema::hasTable('school_logos'));
        $this->assertTrue(Schema::hasColumn('schools', 'logo_path'));
        $this->assertSame('school-logos/'.$this->alpha->id.'.png', DB::table('schools')->where('id', $this->alpha->id)->value('logo_path'));
        $this->assertSame('school-logos/'.$this->beta->id.'.webp', DB::table('schools')->where('id', $this->beta->id)->value('logo_path'));
        $this->assertSame($betaBytes, Storage::disk('local')->get('school-logos/'.$this->beta->id.'.webp'));

        // And forward again: both come back from the files down() wrote.
        $migration->up();
        $this->assertSame(2, SchoolLogo::count());
        $this->assertSame($betaBytes, SchoolLogo::find($this->beta->id)->bytes());
        $this->assertSame('image/webp', SchoolLogo::find($this->beta->id)->mime);
    }
}
