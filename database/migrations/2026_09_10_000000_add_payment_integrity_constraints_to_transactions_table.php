<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment-integrity constraints.
     *
     * `reference` is the identifier we generate and hand to Paystack, and it is how
     * both the browser callback and the webhook resolve a payment back to a local
     * transaction. It must therefore be unique, so that one Paystack reference can
     * only ever settle one transaction. `paystack_reference` is Paystack's own id
     * for the charge and is likewise one-to-one.
     *
     * `paid_at` records the single moment a transaction transitioned to success,
     * giving replay handling an auditable marker.
     */
    public function up(): void
    {
        $this->guardAgainstDuplicates('reference');
        $this->guardAgainstDuplicates('paystack_reference');

        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('status');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('reference', 'transactions_reference_unique');
            $table->unique('paystack_reference', 'transactions_paystack_reference_unique');
        });
    }

    /**
     * Fail loudly and usefully rather than letting the index creation blow up with
     * an opaque driver error. Duplicate references mean real data needs a decision
     * before this constraint can be applied.
     */
    private function guardAgainstDuplicates(string $column): void
    {
        if (! Schema::hasColumn('transactions', $column)) {
            return;
        }

        $duplicates = DB::table('transactions')
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column);

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                "Cannot add a unique index on transactions.{$column}: ".$duplicates->count().
                ' duplicate value(s) exist, e.g. ['.$duplicates->take(5)->implode(', ').
                ']. Resolve these rows before migrating.'
            );
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_reference_unique');
            $table->dropUnique('transactions_paystack_reference_unique');
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'paid_at')) {
                $table->dropColumn('paid_at');
            }
        });
    }
};
