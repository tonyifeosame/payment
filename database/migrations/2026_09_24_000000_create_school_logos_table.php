<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * H3: school logos move from the container filesystem into the database.
 *
 * Until now a logo was a file on the `local` disk and schools.logo_path its
 * name. On Render that disk is ephemeral and per service: every deploy or
 * restart wiped the file, and the queue worker — which renders the receipt
 * email — never saw a file the web service had written. The three containers
 * share exactly one durable thing, Postgres, so that is where the logo goes:
 * one row per school in school_logos, the image itself as base64 text.
 *
 * Order of operations, each step guarded so the migration is safe to re-run
 * after a partial failure:
 *   1. create school_logos;
 *   2. copy every logo_path whose file is actually present on the local disk
 *      into the table. A missing file is skipped and logged, never invented;
 *      the file itself is left in place;
 *   3. drop schools.logo_path — only after the rows exist.
 *
 * down() is lossless the other way: it writes each row back to the disk,
 * restores logo_path, and only then drops the table.
 */
return new class extends Migration
{
    private const DISK = 'local';

    private const DIR = 'school-logos';

    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('school_logos')) {
            Schema::create('school_logos', function (Blueprint $table) {
                $table->foreignId('school_id')->primary()->constrained('schools')->cascadeOnDelete();
                $table->string('mime', 64);
                $table->unsignedInteger('size');
                // Base64, not bytea: see App\Models\SchoolLogo.
                $table->text('data');
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('schools', 'logo_path')) {
            $this->backfillFromDisk();

            Schema::table('schools', function (Blueprint $table) {
                $table->dropColumn('logo_path');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('schools', 'logo_path')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->string('logo_path')->nullable()->after('address');
            });
        }

        if (Schema::hasTable('school_logos')) {
            $this->restoreToDisk();

            Schema::dropIfExists('school_logos');
        }
    }

    /** Step 2: file on disk -> row. Skips (and reports) files that are gone. */
    private function backfillFromDisk(): void
    {
        $disk = Storage::disk(self::DISK);
        $now = now();

        $schools = DB::table('schools')->whereNotNull('logo_path')->orderBy('id')->get(['id', 'logo_path']);

        foreach ($schools as $school) {
            if (DB::table('school_logos')->where('school_id', $school->id)->exists()) {
                continue; // already copied by an earlier, interrupted run
            }

            if (! $disk->exists($school->logo_path)) {
                Log::warning('school_logos backfill: logo file missing, school keeps no logo', [
                    'school_id' => $school->id,
                    'logo_path' => $school->logo_path,
                ]);

                continue;
            }

            $bytes = $disk->get($school->logo_path);
            $extension = strtolower(pathinfo($school->logo_path, PATHINFO_EXTENSION));
            $mime = $disk->mimeType($school->logo_path)
                ?: (array_search($extension === 'jpeg' ? 'jpg' : $extension, self::EXTENSIONS, true) ?: 'application/octet-stream');

            DB::table('school_logos')->insert([
                'school_id' => $school->id,
                'mime' => $mime,
                'size' => strlen($bytes),
                'data' => base64_encode($bytes),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Rollback of step 2: row -> file on disk, logo_path pointing at it. */
    private function restoreToDisk(): void
    {
        $disk = Storage::disk(self::DISK);

        DB::table('school_logos')->orderBy('school_id')->get()->each(function ($logo) use ($disk) {
            $path = self::DIR.'/'.$logo->school_id.'.'.(self::EXTENSIONS[$logo->mime] ?? 'bin');

            $disk->put($path, base64_decode($logo->data));

            DB::table('schools')->where('id', $logo->school_id)->update(['logo_path' => $path]);
        });
    }
};
