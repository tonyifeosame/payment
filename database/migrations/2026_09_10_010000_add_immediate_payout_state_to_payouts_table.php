<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn `payouts` into a per-transaction payout ledger with a real transfer
     * state machine (Model A: settle a payment, immediately owe the school).
     *
     * The table was built for daily batches, so the window columns are required and
     * there is no link to the transaction that generated the obligation and no
     * uniqueness anywhere. That makes duplicate transfers impossible to prevent.
     *
     *   transaction_id  one payout per settled transaction (UNIQUE, nullable so the
     *                   historical batch rows remain valid — SQL permits many NULLs
     *                   in a unique index on PostgreSQL, MySQL and SQLite alike)
     *   reference       our durable idempotency key, sent to Paystack as the transfer
     *                   reference so a re-send cannot create a second transfer
     *   transfer_code   Paystack's own handle for the transfer (UNIQUE)
     *   attempts        how many times we have tried to initiate
     *   last_error      why the last attempt did not succeed
     *   initiated_at    when we first handed it to Paystack
     *   completed_at    when it reached a terminal state
     *
     * The window columns become nullable because an immediate payout has no window.
     */
    public function up(): void
    {
        $this->guardAgainstDuplicates();

        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'transaction_id')) {
                $table->foreignId('transaction_id')->nullable()->after('school_id')
                    ->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('payouts', 'reference')) {
                $table->string('reference')->nullable()->after('transaction_id');
            }
            if (! Schema::hasColumn('payouts', 'attempts')) {
                $table->unsignedInteger('attempts')->default(0)->after('status');
            }
            if (! Schema::hasColumn('payouts', 'last_error')) {
                $table->text('last_error')->nullable()->after('attempts');
            }
            if (! Schema::hasColumn('payouts', 'initiated_at')) {
                $table->timestamp('initiated_at')->nullable()->after('last_error');
            }
            if (! Schema::hasColumn('payouts', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('initiated_at');
            }
        });

        // An immediate payout covers a single transaction, not a date range.
        Schema::table('payouts', function (Blueprint $table) {
            $table->date('payout_date')->nullable()->change();
            $table->dateTime('start_at')->nullable()->change();
            $table->dateTime('end_at')->nullable()->change();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->unique('transaction_id', 'payouts_transaction_id_unique');
            $table->unique('reference', 'payouts_reference_unique');
            $table->unique('transfer_code', 'payouts_transfer_code_unique');
            $table->index('status', 'payouts_status_index');
        });
    }

    /**
     * Refuse to add uniqueness over data that already violates it, with a message
     * that says what to fix rather than an opaque driver error.
     */
    private function guardAgainstDuplicates(): void
    {
        foreach (['transfer_code'] as $column) {
            if (! Schema::hasColumn('payouts', $column)) {
                continue;
            }

            $duplicates = DB::table('payouts')
                ->select($column)
                ->whereNotNull($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->pluck($column);

            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException(
                    "Cannot add a unique index on payouts.{$column}: ".$duplicates->count().
                    ' duplicate value(s) exist, e.g. ['.$duplicates->take(5)->implode(', ').
                    ']. Resolve these rows before migrating.'
                );
            }
        }
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique('payouts_transaction_id_unique');
            $table->dropUnique('payouts_reference_unique');
            $table->dropUnique('payouts_transfer_code_unique');
            $table->dropIndex('payouts_status_index');
        });

        Schema::table('payouts', function (Blueprint $table) {
            if (Schema::hasColumn('payouts', 'transaction_id')) {
                $table->dropConstrainedForeignId('transaction_id');
            }
            $table->dropColumn(array_values(array_filter(
                ['reference', 'attempts', 'last_error', 'initiated_at', 'completed_at'],
                fn ($c) => Schema::hasColumn('payouts', $c)
            )));
        });
    }
};
