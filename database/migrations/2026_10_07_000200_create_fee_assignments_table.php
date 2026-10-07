<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Class-level fee assignment: which class levels a fee applies to.
     *
     *   fee_assignments              one row per (fee, class level). A fee with NO
     *                                rows applies to every student, exactly as every
     *                                fee did before this table existed; a fee with
     *                                rows is payable only for a student whose
     *                                class_level_id is one of them. school_id is
     *                                stored so every lookup can be tenant-scoped.
     *
     *   subcategories.is_tuition     the school's own statement that this is a
     *                                class's main fee (tuition), so the payment page
     *                                can pick it for the parent. Nothing is inferred
     *                                from the fee's name.
     *
     * Additive only: no existing fee is assigned, every fee keeps is_tuition = false,
     * and no student or transaction row is touched. Existing fees therefore behave
     * exactly as before until an administrator assigns them.
     */
    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            if (! Schema::hasColumn('subcategories', 'is_tuition')) {
                $table->boolean('is_tuition')->default(false)->after('allows_quantity');
            }
        });

        Schema::create('fee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcategory_id')->constrained('subcategories')->cascadeOnDelete();
            // A class level with assignments cannot be deleted from the admin
            // (ClassLevelController::destroy); deleting it would otherwise turn a
            // class-only fee into one every student can pay.
            $table->foreignId('class_level_id')->constrained('class_levels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['subcategory_id', 'class_level_id'], 'fee_assignments_fee_level_unique');
            $table->index(['school_id', 'class_level_id'], 'fee_assignments_school_level_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_assignments');

        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropColumn('is_tuition');
        });
    }
};
