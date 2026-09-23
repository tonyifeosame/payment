<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a fee may be bought in multiples (L1).
     *
     * subcategories.allows_quantity    false: the fee is a single charge and the
     *                                  checkout accepts only a quantity of 1.
     *                                  true: the parent may choose 1–100 units
     *                                  (uniforms, books), charged price × quantity.
     *
     * Until now this was decided at checkout by whether the CATEGORY name
     * contained "school fee" — text an admin can edit freely, so a category named
     * "Tuition" let a term fee be paid for 1–100 times. New fees default to a
     * single charge; the school opts a fee in on the fee form.
     *
     * The backfill below is the one and only place the old name rule is used, and
     * it runs once. Existing GENERAL fees (no term) keep the behaviour they have
     * today; TERM fees stay single-charge whatever their category is called,
     * because a term fee is charged once per student per term and a payment is
     * recorded against exactly one student. Transactions, prices and the markup
     * are not touched.
     */
    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            if (! Schema::hasColumn('subcategories', 'allows_quantity')) {
                $table->boolean('allows_quantity')->default(false)->after('price');
            }
        });

        $this->backfill();
    }

    /**
     * Enable quantity for existing general fees whose category the old rule did
     * not treat as school fees. Mirrors PaymentCheckoutService's former check,
     * str_contains(strtolower($category->name), 'school fee'). Only ever sets
     * true, and only on rows that had no setting before this migration.
     */
    private function backfill(): void
    {
        DB::table('subcategories')
            ->whereNull('academic_term_id')
            ->whereIn('category_id', DB::table('categories')
                ->select('id')
                ->whereRaw('LOWER(name) NOT LIKE ?', ['%school fee%']))
            ->update(['allows_quantity' => true]);
    }

    public function down(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropColumn('allows_quantity');
        });
    }
};
