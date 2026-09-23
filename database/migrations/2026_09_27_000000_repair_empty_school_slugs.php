<?php

use App\Models\School;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * N1: give every school stored with an EMPTY slug a real one.
 *
 * A name with no sluggable characters ("###", emoji, CJK) used to slugify to
 * '' — and the row was saved before registration crashed building its
 * dashboard URL. The slug is the route key, so such a school cannot log in and
 * has no payment page: no URL for it could ever be generated, which also means
 * no link to it can have been shared, so replacing the slug breaks nothing.
 *
 * Only rows whose slug is exactly '' are touched, each given the slug
 * School::availableSlugFor() now mints: the name's slug, or `school` when that is
 * empty, stepping -1, -2, … past reserved segments and slugs already taken.
 * Every non-empty slug — including the `-1` / `-2` slugs later symbol-only
 * schools received — is left exactly as it is, as is every other column.
 *
 * Query builder only, like the other migrations; the reserved list and fallback
 * are read from the model's constants so the two cannot disagree. The unique
 * index on slug means at most one empty row can exist, but the loop does not
 * rely on it.
 *
 * Irreversible by design: down() does not restore an empty slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('schools')
            ->where('slug', '')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function ($school) {
                DB::table('schools')->where('id', $school->id)->update([
                    'slug' => $this->availableSlugFor((string) $school->name),
                ]);
            });
    }

    public function down(): void
    {
        // Nothing to undo: an empty slug is never a state worth restoring.
    }

    /** Same rules as School::availableSlugFor(), against the table as it stands. */
    private function availableSlugFor(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = School::FALLBACK_SLUG;
        }

        $slug = $base;
        $i = 1;
        while (in_array($slug, School::RESERVED_SLUGS, true) || DB::table('schools')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.($i++);
        }

        return $slug;
    }
};
