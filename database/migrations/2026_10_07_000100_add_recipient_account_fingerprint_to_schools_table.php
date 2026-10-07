<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M2: which bank account a stored Paystack recipient code was created for.
     *
     * A payout job could create a recipient from the account it had loaded while
     * the school was changing its bank account, then write that (old) recipient
     * code back over the cleared column — every later payout went to the old
     * account. Recipient codes are now saved only by a conditional update that
     * still matches the account they were created from, together with a
     * SHA-256 of that account (bank code + number; not the number itself), and a
     * code whose fingerprint no longer matches the school's current account is
     * never used.
     *
     * Existing codes are stamped with their school's current account. Every bank
     * change through the application has always cleared the code, so a stored
     * code belongs to the current account unless the race above already happened;
     * that residual case cannot be detected from the database alone.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            if (! Schema::hasColumn('schools', 'paystack_recipient_account')) {
                $table->string('paystack_recipient_account', 64)->nullable();
            }
        });

        DB::table('schools')
            ->whereNotNull('paystack_recipient_code')
            ->whereNull('paystack_recipient_account')
            ->orderBy('id')
            ->each(function ($school) {
                DB::table('schools')->where('id', $school->id)->update([
                    'paystack_recipient_account' => hash('sha256', $school->bank_code.'|'.$school->account_number),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('paystack_recipient_account');
        });
    }
};
