<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * School-defined class progression and the promotion ledger.
     *
     * class_levels is the ordered list a school configures ("Primary 1 … SS 3");
     * `position` alone defines the normal progression, so nothing is ever inferred
     * from a class name. students.class_level_id is the relational truth; the old
     * free-text students.class_name is KEPT as the display snapshot the payment
     * page, receipts and CSV export already read, and is written from the level.
     *
     * No data is converted here. Existing class_name values stay untouched and are
     * mapped to levels only through an explicit admin action on the Classes page
     * (see ClassLevelController::assignLegacy), never by string similarity.
     *
     * student_promotions / student_promotion_entries record every bulk promotion:
     * the entries' unique (student_id, to_academic_session_id) index is what makes
     * a repeated promotion into the same session impossible at the database level.
     */
    public function up(): void
    {
        Schema::create('class_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['school_id', 'name'], 'class_levels_school_name_unique');
            $table->index(['school_id', 'position'], 'class_levels_school_position_index');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('class_level_id')->nullable()->after('class_name')
                ->constrained('class_levels')->nullOnDelete();
            // active | graduated | left. Graduation never deletes a student.
            $table->string('status', 20)->default('active')->after('class_level_id');
            $table->index(['school_id', 'status'], 'students_school_status_index');
        });

        Schema::create('student_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('to_academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            // The only admin role is the school's own login; nothing more identifying is kept.
            $table->string('performed_by', 50)->default('school_admin');
            $table->unsignedInteger('promoted_count')->default(0);
            $table->unsignedInteger('graduated_count')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);
            $table->timestamps();

            $table->index(['school_id', 'created_at'], 'student_promotions_school_created_index');
        });

        Schema::create('student_promotion_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_promotion_id')->constrained('student_promotions')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('to_academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('from_class_level_id')->nullable()->constrained('class_levels')->nullOnDelete();
            $table->foreignId('to_class_level_id')->nullable()->constrained('class_levels')->nullOnDelete();
            // Names are snapshotted so the history stays readable after a rename or removal.
            $table->string('from_class_name', 100)->nullable();
            $table->string('to_class_name', 100)->nullable();
            $table->string('action', 20); // promoted | graduated
            $table->timestamps();

            // One promotion per student per target session: the server-side double-click guard.
            $table->unique(['student_id', 'to_academic_session_id'], 'promotion_entries_student_session_unique');
            $table->index(['school_id', 'student_id'], 'promotion_entries_school_student_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_promotion_entries');
        Schema::dropIfExists('student_promotions');

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_school_status_index');
            $table->dropConstrainedForeignId('class_level_id');
            $table->dropColumn('status');
        });

        Schema::dropIfExists('class_levels');
    }
};
