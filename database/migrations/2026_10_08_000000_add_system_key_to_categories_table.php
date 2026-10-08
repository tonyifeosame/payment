<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * categories.system_key marks a category FEYRA provides itself rather than one
     * the school typed. The only one today is 'school_fees' — the built-in "School
     * Fees" category every school's main (tuition) fees live in.
     *
     * Backfill: a school that already typed its own "School Fees" (any spelling:
     * "school fees", "School fee", "SCHOOL FEES" …) has its OLDEST such category
     * adopted as the built-in one and its name tidied to "School Fees". Nothing is
     * merged or deleted: other variants keep their rows, fees and payments, and
     * every transaction keeps its category id and its snapshot name. A school with
     * no such category gets the built-in one the first time the admin opens Fees.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'system_key')) {
                $table->string('system_key', 32)->nullable()->after('name');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            // NULLs are distinct, so ordinary categories are unconstrained; a school
            // can hold each built-in category at most once.
            $table->unique(['school_id', 'system_key'], 'categories_school_system_key_unique');
        });

        $adopted = [];
        DB::table('categories')->whereNotNull('school_id')->whereNull('system_key')->orderBy('id')
            ->select(['id', 'school_id', 'name'])
            ->lazyById()
            ->each(function ($row) use (&$adopted) {
                if (isset($adopted[$row->school_id]) || $this->normalize((string) $row->name) !== 'schoolfee') {
                    return;
                }
                $adopted[$row->school_id] = true;
                DB::table('categories')->where('id', $row->id)->update([
                    'system_key' => 'school_fees',
                    'name' => 'School Fees',
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_school_system_key_unique');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('system_key');
        });
    }

    /** Frozen copy of Category::normalizeName() as it was when this migration was written. */
    private function normalize(string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name))), -1, PREG_SPLIT_NO_EMPTY);

        return implode('', array_map(fn (string $w) => Str::singular($w), $words));
    }
};
