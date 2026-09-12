<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a fee a term, and a payment a student and a period.
     *
     * subcategories.academic_term_id   which term this fee is charged for. NULL means
     *                                  "general" — a uniform or a textbook is not tied
     *                                  to a term and stays payable in any of them.
     *
     * transactions.student_id          the verified student the payment is for
     * transactions.academic_session_id / academic_term_id
     *                                  the period the parent paid for
     *
     * All three are nullable and nullOnDelete so every historical transaction
     * remains valid, and so deleting a student or a session can never delete a
     * financial record. Because those references can be nulled, the details a
     * receipt prints are ALSO snapshotted at payment time (student_name,
     * student_admission_number, student_class, session_name, term_name) — the same
     * pattern this table already uses for category_name / subcategory_name.
     *
     * fee_amount / service_fee         the school's share and the platform fee,
     *                                  promoted out of meta_data JSON into real
     *                                  columns so dashboards and exports can SUM and
     *                                  GROUP BY them in SQL. meta_data stays the
     *                                  authority for receipts and payouts
     *                                  (Transaction::receiptBreakdown is untouched);
     *                                  these columns are a query-friendly copy of it.
     */
    public function up(): void
    {
        Schema::table('subcategories', function (Blueprint $table) {
            if (! Schema::hasColumn('subcategories', 'academic_term_id')) {
                $table->foreignId('academic_term_id')->nullable()->after('category_id')
                    ->constrained('academic_terms')->nullOnDelete();
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'student_id')) {
                $table->foreignId('student_id')->nullable()->after('school_id')
                    ->constrained('students')->nullOnDelete();
            }
            if (! Schema::hasColumn('transactions', 'academic_session_id')) {
                $table->foreignId('academic_session_id')->nullable()->after('student_id')
                    ->constrained('academic_sessions')->nullOnDelete();
            }
            if (! Schema::hasColumn('transactions', 'academic_term_id')) {
                $table->foreignId('academic_term_id')->nullable()->after('academic_session_id')
                    ->constrained('academic_terms')->nullOnDelete();
            }
            foreach (['student_name', 'student_admission_number', 'student_class', 'session_name', 'term_name'] as $column) {
                if (! Schema::hasColumn('transactions', $column)) {
                    $table->string($column)->nullable();
                }
            }
            if (! Schema::hasColumn('transactions', 'fee_amount')) {
                $table->decimal('fee_amount', 12, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('transactions', 'service_fee')) {
                $table->decimal('service_fee', 12, 2)->nullable()->after('fee_amount');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Dashboard and list queries always start from the school and filter by
            // status and date; student lookups start from the school and student.
            $table->index(['school_id', 'status', 'paid_at'], 'transactions_school_status_paid_index');
            $table->index(['school_id', 'academic_term_id'], 'transactions_school_term_index');
            $table->index(['school_id', 'category_id'], 'transactions_school_category_index');
        });

        $this->backfillFeeColumns();
    }

    /**
     * Populate fee_amount / service_fee for existing rows from meta_data, using the
     * same rules as Transaction::receiptBreakdown so the two can never disagree:
     * the charged amount always wins, and an unreconcilable split is re-derived
     * from it. Rows without a recorded base_amount are left NULL — their split is
     * unknown, and PayoutService already treats them as needing review.
     */
    private function backfillFeeColumns(): void
    {
        DB::table('transactions')
            ->select(['id', 'amount', 'meta_data'])
            ->whereNull('fee_amount')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $meta = $this->decodeMeta($row->meta_data);

                    if (! array_key_exists('base_amount', $meta) || ! is_numeric($meta['base_amount'])) {
                        continue;
                    }

                    $total = round((float) $row->amount, 2);
                    $fee = round((float) $meta['base_amount'], 2);
                    $service = isset($meta['markup_amount']) && is_numeric($meta['markup_amount'])
                        ? round((float) $meta['markup_amount'], 2)
                        : round($total - $fee, 2);

                    if (round($fee + $service, 2) !== $total) {
                        $service = round($total - $fee, 2);
                    }
                    if ($service < 0) {
                        $service = 0.0;
                        $fee = $total;
                    }

                    DB::table('transactions')->where('id', $row->id)->update([
                        'fee_amount' => $fee,
                        'service_fee' => $service,
                    ]);
                }
            });
    }

    /** meta_data may be JSON, or JSON encoded twice by an older code path. */
    private function decodeMeta($value): array
    {
        for ($depth = 0; $depth < 3 && is_string($value); $depth++) {
            $decoded = json_decode($value, true);
            if ($decoded === null) {
                break;
            }
            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_school_status_paid_index');
            $table->dropIndex('transactions_school_term_index');
            $table->dropIndex('transactions_school_category_index');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_id');
            $table->dropConstrainedForeignId('academic_session_id');
            $table->dropConstrainedForeignId('academic_term_id');
            $table->dropColumn([
                'student_name', 'student_admission_number', 'student_class',
                'session_name', 'term_name', 'fee_amount', 'service_fee',
            ]);
        });

        Schema::table('subcategories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_term_id');
        });
    }
};
