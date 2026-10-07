<?php

namespace App\Console\Commands\Concerns;

use App\Models\School;

trait ResolvesSchool
{
    /** A school by numeric id or by slug, or null (with an error printed). */
    protected function resolveSchool(string $key): ?School
    {
        $school = ctype_digit($key) ? School::find((int) $key) : School::where('slug', $key)->first();

        if (! $school) {
            $this->error("No school with id or slug \"{$key}\".");
        }

        return $school;
    }
}
