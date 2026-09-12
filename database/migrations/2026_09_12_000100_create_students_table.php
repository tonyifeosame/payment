<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Students, as a first-class tenant-scoped record.
     *
     * History: `transactions.admission_number` was a free-text column typed by the
     * payer and was dropped (2025_10_07). It is deliberately NOT restored. A payment
     * now references a student row that the school itself created, and the receipt
     * snapshots that student's details at payment time (see the transactions
     * migration that follows). The parent proves which student they mean by quoting
     * the admission number; the server resolves it within the current school only.
     *
     * Admission numbers are unique per school, not globally — two schools can both
     * have "2024/001".
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('admission_number', 50);
            $table->string('class_name', 100);
            // The session the student was enrolled/registered in. Nullable so a
            // school can create its roster before it has set up sessions.
            $table->foreignId('academic_session_id')->nullable()
                ->constrained('academic_sessions')->nullOnDelete();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->string('guardian_email')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'admission_number'], 'students_school_admission_unique');
            $table->index(['school_id', 'full_name'], 'students_school_name_index');
            $table->index(['school_id', 'class_name'], 'students_school_class_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
