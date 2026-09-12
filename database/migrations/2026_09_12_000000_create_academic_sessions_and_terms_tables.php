<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment period a fee and a payment belong to.
     *
     * A school runs academic sessions ("2026/2027"), each of which has exactly three
     * terms. Terms are real rows rather than an enum column so that a fee, a
     * transaction and the school's "current term" pointer can all reference one
     * durable id, and so that per-term dates can be attached later without another
     * schema change.
     *
     * Nothing existing is altered: fees and transactions gain nullable references in
     * the follow-up migration, so every historical row remains valid.
     */
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20); // e.g. 2026/2027
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            // A school cannot have two "2026/2027" sessions; two schools can.
            $table->unique(['school_id', 'name'], 'academic_sessions_school_name_unique');
        });

        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            // school_id is denormalised from the session so tenant checks are one
            // column read, never a join.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number'); // 1, 2, 3
            $table->string('name', 50);            // First Term, Second Term, Third Term
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->unique(['academic_session_id', 'number'], 'academic_terms_session_number_unique');
            $table->index('school_id', 'academic_terms_school_index');
        });

        // The school's admin-selected "current term". Nullable: a freshly registered
        // school has no sessions yet and the dashboard must cope with that.
        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('current_academic_term_id')->nullable()->after('paystack_recipient_code')
                ->constrained('academic_terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_academic_term_id');
        });

        Schema::dropIfExists('academic_terms');
        Schema::dropIfExists('academic_sessions');
    }
};
