<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payout verification (H3).
     *
     * Registration is open and payouts were automatic, so anyone could register a
     * "school" with any resolvable bank account and be paid every parent's money
     * within the hour. Payments still settle as before; only the TRANSFER to the
     * school now waits until the school is eligible:
     *
     *   schools.payouts_approved_at   set by an operator (`schools:approve-payouts`)
     *                                 once the school has been verified. Null means
     *                                 payments are collected and recorded, and the
     *                                 payouts wait.
     *   schools.payout_hold_until     a cooling-off hold placed by every change of
     *                                 bank account, so a hijacked admin session
     *                                 cannot redirect money before the school sees
     *                                 the change notice. Lifted automatically when
     *                                 it passes, or early by an operator.
     *
     * Existing schools keep being paid: they are backfilled as approved at the
     * moment this migration runs. That preserves today's behaviour for every
     * school already on the platform; review them with `schools:payout-status
     * --all` and suspend any that should not have been.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (! Schema::hasColumn('schools', 'payouts_approved_at')) {
                $table->timestamp('payouts_approved_at')->nullable();
            }
            if (! Schema::hasColumn('schools', 'payout_hold_until')) {
                $table->timestamp('payout_hold_until')->nullable();
            }
        });

        DB::table('schools')->whereNull('payouts_approved_at')->update(['payouts_approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['payouts_approved_at', 'payout_hold_until']);
        });
    }
};
