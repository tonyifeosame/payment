<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A main (tuition) school fee is paid once per student, fee and term.
     *
     *   transactions.obligation_key          written at checkout when the fee is a main
     *                                        fee and the payment names a student and a
     *                                        term: "student:fee:term". Null for every
     *                                        other payment, which the rule never touches.
     *
     *   transactions.settled_obligation_key  the same key, copied ONLY when the row
     *                                        becomes `success`. Unique, so the database
     *                                        itself refuses a second successful payment
     *                                        of one obligation even if two settlements
     *                                        race past every application-level check.
     *                                        NULLs are not compared by a unique index on
     *                                        SQLite, PostgreSQL or MySQL, so pending,
     *                                        failed and mismatch rows never collide.
     *
     * Additive only: both columns start null and no existing row — successful or
     * otherwise — is rewritten. Payments made before this migration still count as
     * paid: checkout looks them up by student, fee and term directly.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'obligation_key')) {
                $table->string('obligation_key', 64)->nullable();
                $table->index('obligation_key', 'transactions_obligation_key_index');
            }
            if (! Schema::hasColumn('transactions', 'settled_obligation_key')) {
                $table->string('settled_obligation_key', 64)->nullable();
                $table->unique('settled_obligation_key', 'transactions_settled_obligation_key_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_settled_obligation_key_unique');
            $table->dropIndex('transactions_obligation_key_index');
            $table->dropColumn(['obligation_key', 'settled_obligation_key']);
        });
    }
};
