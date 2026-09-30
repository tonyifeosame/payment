<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\School;
use App\Models\SchoolLogo;
use App\Support\SchoolLogoImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsLogoFixtures;
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
    use BuildsLogoFixtures, InteractsWithSchools, RefreshDatabase;

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
        // What is stored is the normalised image, not the upload (SchoolLogoImage).
        $bytes = SchoolLogoImage::normalize($file->get())['bytes'];

        $this->upload($this->alpha, $file);

        $logo = SchoolLogo::find($this->alpha->id);
        $this->assertNotNull($logo);
        $this->assertSame('image/png', $logo->mime);
        $this->assertSame(strlen($bytes), $logo->size, 'size is the stored bytes');
        $this->assertSame($bytes, $logo->bytes(), 'the stored bytes must round-trip exactly');
        $this->assertSame(base64_encode($bytes), $logo->getRawOriginal('data'), 'stored as base64 text, safe for every driver');

        $this->assertSame([], Storage::disk('local')->allFiles(), 'no logo may touch the container filesystem');
        $this->assertFalse(Schema::hasColumn('schools', 'logo_path'));
    }

    public function test_logo_route_returns_exactly_the_stored_image(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));

        $response = $this->get('/s/alpha/logo')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=86400, public')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(SchoolLogo::find($this->alpha->id)->bytes(), $response->getContent());
    }

    public function test_replacing_a_png_with_a_webp_replaces_the_row(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));
        $this->assertDatabaseHas('school_logos', ['school_id' => $this->alpha->id, 'mime' => 'image/png']);

        $webp = UploadedFile::fake()->image('logo.webp', 48, 48);
        $webpBytes = SchoolLogoImage::normalize($webp->get())['bytes'];
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
        $alphaBytes = SchoolLogoImage::normalize($alphaFile->get())['bytes'];
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
        $betaBytes = SchoolLogoImage::normalize($betaFile->get())['bytes'];
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
    // Normalisation on upload (SchoolLogoImage)
    // -----------------------------------------------------------------------

    public function test_a_large_upload_is_stored_resized_in_its_own_format(): void
    {
        foreach (['logo.jpg' => 'image/jpeg', 'logo.png' => 'image/png', 'logo.webp' => 'image/webp'] as $name => $mime) {
            $file = UploadedFile::fake()->image($name, 2000, 1200);
            $this->upload($this->alpha, $file);

            $logo = SchoolLogo::find($this->alpha->id);
            $this->assertSame($mime, $logo->mime, $name);
            $this->assertSame([512, 307], array_slice(getimagesizefromstring($logo->bytes()), 0, 2), "{$name}: longest side 512, aspect ratio kept");
            $this->assertSame(strlen($logo->bytes()), $logo->size, "{$name}: size is the stored bytes");
            $this->assertSame($logo->bytes(), $this->get('/s/alpha/logo')->assertOk()->assertHeader('Content-Type', $mime)->getContent());
        }
    }

    public function test_an_image_over_4000_pixels_is_rejected_with_a_clear_message(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings', $this->profile(['logo' => UploadedFile::fake()->image('wide.png', 4001, 10)]))
            ->assertRedirect('/admin/alpha/settings')
            ->assertSessionHasErrors(['logo' => 'The logo must be at most 4000 × 4000 pixels.']);

        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);
    }

    public function test_a_decompression_bomb_upload_is_rejected_from_its_header(): void
    {
        $bomb = UploadedFile::fake()->createWithContent('bomb.png', $this->bombPng(10000));

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings', $this->profile(['logo' => $bomb]))
            ->assertRedirect('/admin/alpha/settings')
            ->assertSessionHasErrors(['logo' => 'The logo must be at most 4000 × 4000 pixels.']);

        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before, 'never decoded: decoding would need ~400 MB');
        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);
    }

    public function test_an_animated_webp_is_rejected_with_a_clear_message_not_an_error(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings', $this->profile(['logo' => UploadedFile::fake()->createWithContent('moving.webp', $this->animatedWebp())]))
            ->assertRedirect('/admin/alpha/settings')
            ->assertSessionHasErrors(['logo' => 'Animated images cannot be used as a logo. Please upload a still PNG, JPG or WebP image.']);

        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);
    }

    public function test_a_jpeg_with_malformed_exif_is_accepted_upright_not_a_server_error(): void
    {
        // libjpeg warns about this file ("corrupt data"); inside a request an
        // unsilenced warning would become an exception and a 500.
        ob_start();
        imagejpeg(imagecreatetruecolor(120, 80), null, 90);
        $jpeg = (string) ob_get_clean();
        $malformed = substr($jpeg, 0, 2)."\xFF\xE1\x00\x40Exif\0\0MM".substr($jpeg, 2);

        $this->upload($this->alpha, UploadedFile::fake()->createWithContent('logo.jpg', $malformed));

        $logo = SchoolLogo::find($this->alpha->id);
        $this->assertSame('image/jpeg', $logo->mime);
        $this->assertSame([120, 80], array_slice(getimagesizefromstring($logo->bytes()), 0, 2));
    }

    public function test_gif_and_bmp_are_still_rejected(): void
    {
        foreach (['logo.gif', 'logo.bmp'] as $name) {
            $this->actingAsSchoolAdmin($this->alpha)
                ->from('/admin/alpha/settings')
                ->put('/admin/alpha/settings', $this->profile(['logo' => UploadedFile::fake()->image($name, 16, 16)]))
                ->assertRedirect('/admin/alpha/settings')
                ->assertSessionHasErrors('logo');
        }

        $this->assertDatabaseMissing('school_logos', ['school_id' => $this->alpha->id]);
    }

    public function test_a_rejected_logo_leaves_the_rest_of_the_update_unapplied(): void
    {
        $this->giveLogo($this->alpha);
        $before = SchoolLogo::find($this->alpha->id)->bytes();

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/settings')
            ->put('/admin/alpha/settings', $this->profile(['phone' => '0801 234 5678', 'logo' => UploadedFile::fake()->createWithContent('moving.webp', $this->animatedWebp())]))
            ->assertSessionHasErrors('logo');

        $this->assertSame($before, SchoolLogo::find($this->alpha->id)->bytes(), 'the existing logo is untouched');
        $this->assertNull($this->alpha->fresh()->phone, 'nothing else from the rejected submit was saved');
    }

    public function test_existing_logos_are_served_exactly_as_stored_and_never_reprocessed(): void
    {
        // A logo stored before normalisation: 1,600px wide, never resized. It must
        // be served byte-for-byte; nothing in this change rewrites existing rows.
        ob_start();
        imagepng(imagecreatetruecolor(1600, 900));
        $legacy = (string) ob_get_clean();
        SchoolLogo::create(['school_id' => $this->alpha->id, 'mime' => 'image/png', 'size' => strlen($legacy), 'data' => base64_encode($legacy)]);

        $this->assertSame($legacy, $this->get('/s/alpha/logo')->assertOk()->getContent());
        $this->assertSame($legacy, SchoolLogo::find($this->alpha->id)->bytes());
    }

    public function test_pdf_and_email_receive_the_normalised_logo(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 1600, 1000));
        $transaction = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'alpha-ref-1']);

        // The PDF embeds the stored (normalised) image, not the 1,600px upload.
        $dataUri = $this->alpha->fresh()->logoDataUri();
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
        $embedded = base64_decode(substr($dataUri, strlen('data:image/png;base64,')));
        $this->assertSame([512, 320], array_slice(getimagesizefromstring($embedded), 0, 2));
        $this->get(URL::signedRoute('payment.receipt.download', ['transaction' => $transaction->id]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // The email links the same route, which serves the normalised bytes.
        $html = unserialize(serialize(new PaymentReceiptMail($transaction)))->render();
        $this->assertStringContainsString('http://localhost/s/alpha/logo?v=', $html);
        $this->assertSame(SchoolLogo::find($this->alpha->id)->bytes(), $this->get('/s/alpha/logo')->getContent());
    }

    // -----------------------------------------------------------------------
    // Delivery: explicit dimensions and nosniff
    // -----------------------------------------------------------------------

    public function test_every_web_logo_img_has_explicit_width_and_height(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));
        $transaction = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'alpha-ref-1']);
        $admin = fn () => $this->actingAsSchoolAdmin($this->alpha);

        // Admin pages also carry the 40px sidebar logo alongside their own.
        $pages = [
            'admin sidebar (dashboard)' => [$admin()->get('/admin/alpha/dashboard'), [40]],
            'public payment page' => [$this->get('/pay/alpha'), [56]],
            'legacy payment page' => [$this->get('/s/alpha/payment'), [56]],
            'receipt page' => [$this->get(URL::signedRoute('payment.receipt', ['transaction' => $transaction->id])), [56]],
            'share page' => [$admin()->get('/admin/alpha/share'), [40, 64]],
            'settings page' => [$admin()->get('/admin/alpha/settings'), [40, 80]],
        ];

        foreach ($pages as $name => [$response, $sizes]) {
            preg_match_all('/<img\b[^>]*\/s\/alpha\/logo\?v=[^>]*>/', $response->assertOk()->getContent(), $tags);

            $found = [];
            foreach ($tags[0] as $tag) {
                $this->assertMatchesRegularExpression('/ width="(\d+)" height="\1"/', $tag, "{$name}: every logo <img> is sized");
                preg_match('/ width="(\d+)"/', $tag, $m);
                $found[] = (int) $m[1];
            }
            sort($found);
            $this->assertSame($sizes, $found, $name);
        }
    }

    public function test_the_logo_response_is_nosniff_including_when_not_modified(): void
    {
        $this->upload($this->alpha, UploadedFile::fake()->image('logo.png', 64, 64));

        $etag = $this->get('/s/alpha/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->headers->get('ETag');
        $this->get('/s/alpha/logo', ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('X-Content-Type-Options', 'nosniff');
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
