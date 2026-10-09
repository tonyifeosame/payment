<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash school-fee payments recorded by the school itself.
     *
     *   transactions.source                 where the payment came from: `paystack`
     *                                       (every existing row, and every checkout) or
     *                                       `manual` (cash recorded by the school). Only
     *                                       `paystack` rows are FEYRA collections, carry a
     *                                       service fee, or can ever create a payout.
     *
     *   transactions.manual_receipt_number  the school's own cash receipt number, optional.
     *
     *   transactions.active_receipt_key     "school_id:RECEIPT NO" while the cash payment
     *                                       stands; cleared when it is voided. Unique, so
     *                                       one receipt number backs one live cash payment
     *                                       per school, but can be re-used after a void —
     *                                       the same pattern as settled_obligation_key.
     *
     *   transactions.received_by / notes    who received the cash, and why/what — typed
     *                                       by the school admin.
     *
     *   transactions.voided_at / void_reason
     *                                       set once by the void action; the row itself
     *                                       is never deleted or otherwise changed.
     *
     * Additive only: existing rows become source = 'paystack' and nothing else on them
     * is touched.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'source')) {
                $table->string('source', 20)->default('paystack');
            }
            if (! Schema::hasColumn('transactions', 'manual_receipt_number')) {
                $table->string('manual_receipt_number', 100)->nullable();
            }
            if (! Schema::hasColumn('transactions', 'active_receipt_key')) {
                $table->string('active_receipt_key', 140)->nullable();
                $table->unique('active_receipt_key', 'transactions_active_receipt_key_unique');
            }
            if (! Schema::hasColumn('transactions', 'received_by')) {
                $table->string('received_by', 100)->nullable();
            }
            if (! Schema::hasColumn('transactions', 'notes')) {
                $table->string('notes', 500)->nullable();
            }
            if (! Schema::hasColumn('transactions', 'voided_at')) {
                $table->timestamp('voided_at')->nullable();
            }
            if (! Schema::hasColumn('transactions', 'void_reason')) {
                $table->string('void_reason', 500)->nullable();
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['school_id', 'source'], 'transactions_school_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_school_source_index');
            $table->dropUnique('transactions_active_receipt_key_unique');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'source', 'manual_receipt_number', 'active_receipt_key',
                'received_by', 'notes', 'voided_at', 'void_reason',
            ]);
        });
    }
};
